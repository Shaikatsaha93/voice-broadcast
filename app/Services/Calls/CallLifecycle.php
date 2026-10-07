<?php

namespace App\Services\Calls;

use App\Enums\CallStatus;
use App\Enums\CampaignStatus;
use App\Jobs\DispatchCampaignCalls;
use App\Jobs\FinalizeCampaign;
use App\Models\CallAttempt;
use App\Models\CampaignRecipient;
use App\Services\Did\DidSlotManager;
use Illuminate\Support\Facades\DB;

/** Idempotent state handling for a single call attempt. */
class CallLifecycle
{
    public function __construct(private DidSlotManager $slots)
    {
    }

    /** Apply a non-terminal status. Never moves a call backwards (out-of-order safe). */
    public function advance(CallAttempt $attempt, CallStatus $to, array $extra = []): void
    {
        DB::transaction(function () use ($attempt, $to, $extra) {
            $a = CallAttempt::whereKey($attempt->id)->lockForUpdate()->first();
            if ($a->finalized || $to->rank() <= $a->status_rank) {
                return;
            }
            $a->fill($extra + ['status' => $to, 'status_rank' => $to->rank()])->save();
            $this->slots->touch($a);
        });
    }

    /**
     * Finalize exactly once: set outcome, release the DID slot, update the
     * recipient (answered / retry pending / exhausted) and kick the dispatcher.
     */
    public function finalize(CallAttempt $attempt, CallStatus $outcome, array $extra = []): void
    {
        $retryAt = null;

        DB::transaction(function () use ($attempt, $outcome, $extra, &$retryAt) {
            $a = CallAttempt::whereKey($attempt->id)->lockForUpdate()->first();
            if ($a->finalized) {
                return;
            }

            if ($a->answered_at && ! in_array($outcome, [CallStatus::CANCELLED], true)) {
                $outcome = CallStatus::COMPLETED;
            }

            $a->fill($extra + ['status' => $outcome, 'status_rank' => $outcome->rank(), 'finalized' => true, 'ended_at' => $a->ended_at ?? now()])->save();
            $this->slots->release($a);

            $r = CampaignRecipient::whereKey($a->recipient_id)->lockForUpdate()->first();
            $campaign = $a->campaign()->first();

            if ($a->answered_at) {
                $r->update(['status' => 'ANSWERED', 'final_result' => 'ANSWERED']);
            } elseif ($outcome === CallStatus::CANCELLED) {
                $r->update(['status' => 'CANCELLED', 'final_result' => 'CANCELLED']);
            } elseif ($outcome->isRetryable() && $r->attempts_count < $campaign->max_attempts) {
                $retryAt = now()->addSeconds($campaign->retry_delay_seconds);
                $r->update(['status' => 'RETRY_PENDING', 'next_attempt_at' => $retryAt, 'final_result' => $outcome->value]);
            } elseif ($outcome->isRetryable()) {
                $r->update(['status' => 'EXHAUSTED', 'final_result' => $outcome->value]);
            } else {
                $r->update(['status' => 'FAILED', 'final_result' => $outcome->value]);
            }
        });

        $campaign = $attempt->campaign()->first();
        if ($campaign->status === CampaignStatus::RUNNING) {
            DispatchCampaignCalls::dispatch($campaign->id)->delay($retryAt ? max(1, now()->diffInSeconds($retryAt)) : 0);
            FinalizeCampaign::dispatch($campaign->id)->delay(5);
        }
    }

    /**
     * An attempt that never reached Asterisk (campaign paused/cancelled before
     * origination): free the slot and give the recipient back without counting an attempt.
     */
    public function abortQueued(CallAttempt $attempt, bool $cancelled): void
    {
        DB::transaction(function () use ($attempt, $cancelled) {
            $a = CallAttempt::whereKey($attempt->id)->lockForUpdate()->first();
            if ($a->finalized || $a->status !== CallStatus::QUEUED) {
                return;
            }
            $this->slots->release($a);
            $r = CampaignRecipient::whereKey($a->recipient_id)->lockForUpdate()->first();
            $a->delete();
            $r->update($cancelled
                ? ['status' => 'CANCELLED', 'final_result' => 'CANCELLED']
                : ['status' => $r->attempts_count > 1 ? 'RETRY_PENDING' : 'PENDING']);
            $r->decrement('attempts_count');
        });
    }
}
