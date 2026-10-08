<?php

namespace App\Services\Asterisk;

use App\Enums\CallStatus;
use App\Jobs\FinalizeOriginateFailure;
use App\Models\CallAttempt;
use App\Services\Calls\CallLifecycle;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Idempotent, order-tolerant AMI event handling. Events are matched to attempts
 * by Uniqueid (== call_ref, set via Originate ChannelId) or LinkedID.
 */
class AsteriskEventProcessor
{
    /** An unanswered call that ended after at least this many seconds of ringing is "no answer". */
    private const RING_TIMEOUT_SECONDS = 20;

    public function __construct(private CallLifecycle $life)
    {
    }

    public function process(array $e): void
    {
        $ref = $e['Uniqueid'] ?? $e['Linkedid'] ?? null;
        $name = $e['Event'] ?? null;
        if (! $ref || ! $name) {
            return;
        }

        $attempt = CallAttempt::where('call_ref', $ref)->first()
            ?? (isset($e['Linkedid']) ? CallAttempt::where('call_ref', $e['Linkedid'])->first() : null);
        if (! $attempt || $attempt->finalized) {
            return;
        }

        if (! $this->firstTime($name.'|'.$ref.'|'.($e['ChannelStateDesc'] ?? $e['DialStatus'] ?? $e['UserEvent'] ?? $e['Cause'] ?? $e['Response'] ?? '').'|'.($e['Timestamp'] ?? ''))) {
            return; // duplicate delivery
        }

        $ids = ['asterisk_uniqueid' => $e['Uniqueid'] ?? $attempt->asterisk_uniqueid, 'asterisk_linkedid' => $e['Linkedid'] ?? $attempt->asterisk_linkedid, 'channel' => $e['Channel'] ?? $attempt->channel];

        match ($name) {
            'Newchannel' => $this->life->advance($attempt, CallStatus::DIALING, $ids),
            'DialBegin' => $this->life->advance($attempt, CallStatus::DIALING, $ids),
            'Newstate' => $this->newState($attempt, $e, $ids),
            'DialEnd' => $this->dialEnd($attempt, $e, $ids),
            'UserEvent' => $this->userEvent($attempt, $e, $ids),
            'OriginateResponse' => ($e['Response'] ?? '') === 'Failure' ? $this->originateFailed($attempt, $e, $ids) : null,
            'Hangup' => $this->hangup($attempt, $e, $ids),
            default => null,
        };
    }

    private function newState(CallAttempt $a, array $e, array $ids): void
    {
        $state = $e['ChannelStateDesc'] ?? '';
        if ($state === 'Ringing' || $state === 'Ring') {
            $this->life->advance($a, CallStatus::RINGING, $ids);
        } elseif ($state === 'Up') {
            $this->life->advance($a, CallStatus::ANSWERED, $ids + ['answered_at' => $a->answered_at ?? now()]);
        }
    }

    private function dialEnd(CallAttempt $a, array $e, array $ids): void
    {
        $status = strtoupper($e['DialStatus'] ?? '');
        $a->update($ids + ['dial_status' => $status]);
        if ($status === 'ANSWER') {
            $this->life->advance($a, CallStatus::ANSWERED, ['answered_at' => $a->answered_at ?? now()]);
        }
    }

    private function userEvent(CallAttempt $a, array $e, array $ids): void
    {
        match ($e['UserEvent'] ?? '') {
            'PlaybackStart' => $this->life->advance($a, CallStatus::PLAYBACK_STARTED, $ids + ['answered_at' => $a->answered_at ?? now()]),
            'PlaybackComplete' => $this->life->advance($a, CallStatus::PLAYBACK_COMPLETED, $ids + ['answered_at' => $a->answered_at ?? now()]),
            default => null,
        };
    }

    private function hangup(CallAttempt $a, array $e, array $ids): void
    {
        if (($e["Uniqueid"] ?? null) !== $a->call_ref) {
            return; // only the originated channel ends the attempt
        }
        $a->refresh();
        $cause = (string) ($e['Cause'] ?? '');
        $end = now();
        $duration = $a->dialed_at ? max(0, $a->dialed_at->diffInSeconds($end)) : 0;
        $billsec = $a->answered_at ? max(0, $a->answered_at->diffInSeconds($end)) : 0;

        $outcome = $this->outcome($a->dial_status, $cause);
        if ($outcome === CallStatus::COMPLETED && ! $a->answered_at) {
            $outcome = CallStatus::NO_ANSWER;
        }
        // Many carriers end an unanswered call after their ring timeout (~30 s) with cause 21 ("rejected").
        // A real decline comes back within seconds, so after a long ring it is simply "no answer".
        if ($outcome === CallStatus::BUSY && $cause === '21' && $duration >= self::RING_TIMEOUT_SECONDS) {
            $outcome = CallStatus::NO_ANSWER;
        }
        $this->life->finalize($a, $outcome, $ids + ['hangup_cause' => $cause, 'ended_at' => $end, 'duration' => (int) $duration, 'billsec' => (int) $billsec]);
    }

    /** Map Asterisk DialStatus / Q.931 cause to our terminal outcome. */
    public function outcome(?string $dialStatus, string $cause): CallStatus
    {
        return match (true) {
            $dialStatus === 'BUSY' || in_array($cause, ['17', '21'], true) => CallStatus::BUSY, // 21 = call rejected / declined by the callee
            $dialStatus === 'NOANSWER' || in_array($cause, ['18', '19'], true) => CallStatus::NO_ANSWER,
            $dialStatus === 'CANCEL' => CallStatus::NO_ANSWER,
            in_array($cause, ['1', '22', '28'], true) => CallStatus::INVALID_NUMBER,
            in_array($dialStatus, ['CONGESTION', 'CHANUNAVAIL'], true) || in_array($cause, ['34', '38', '41', '42', '44'], true) => CallStatus::TEMPORARY_FAILURE,
            $dialStatus === 'ANSWER' || $cause === '16' => CallStatus::COMPLETED, // answered_at turns this into ANSWERED; unanswered normal clearing is mapped below
            default => CallStatus::FAILED,
        };
    }

    /**
     * Failed originate: remember the coarse reason, then give the Hangup event (it carries the real
     * cause) a few seconds to finalize the attempt. FinalizeOriginateFailure covers the case it never comes.
     */
    private function originateFailed(CallAttempt $a, array $e, array $ids): void
    {
        $reason = (string) ($e['Reason'] ?? '?');
        $a->update($ids + ['hangup_cause' => 'ORIG_'.$reason]);
        FinalizeOriginateFailure::dispatch($a->id, $reason)->delay(3);
    }

    public function finalizeOriginateFailure(CallAttempt $a, string $reason): void
    {
        $outcome = $this->fromReason($reason);
        // Reason 0 after a full ring period (no answer, no busy signal) is an unanswered call, not a failure.
        if ($outcome === CallStatus::FAILED && $reason === '0' && $a->dialed_at && $a->dialed_at->diffInSeconds(now()) >= self::RING_TIMEOUT_SECONDS) {
            $outcome = CallStatus::NO_ANSWER;
        }
        $this->life->finalize($a, $outcome, ['hangup_cause' => 'ORIG_'.$reason]);
    }

    private function fromReason(string $reason): CallStatus
    {
        return match ($reason) {
            '3' => CallStatus::NO_ANSWER, '5' => CallStatus::BUSY, '8' => CallStatus::TEMPORARY_FAILURE, default => CallStatus::FAILED,
        };
    }

    private function firstTime(string $key): bool
    {
        try {
            DB::table('processed_events')->insert(['event_key' => sha1($key), 'created_at' => now()]);

            return true;
        } catch (QueryException) {
            return false;
        }
    }
}
