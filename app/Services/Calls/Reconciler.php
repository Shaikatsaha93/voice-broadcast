<?php

namespace App\Services\Calls;

use App\Enums\CallStatus;
use App\Enums\CampaignStatus;
use App\Jobs\DispatchCampaignCalls;
use App\Models\CallAttempt;
use App\Models\Campaign;
use App\Models\DidSlot;
use App\Services\Asterisk\AsteriskService;
use App\Services\Did\DidSlotManager;
use Illuminate\Support\Facades\Log;

/**
 * Recovers stale DID slots / stuck attempts (worker crash, missed hangup,
 * Asterisk or Redis outage). Safe to run repeatedly.
 */
class Reconciler
{
    public function __construct(private AsteriskService $asterisk, private CallLifecycle $life, private DidSlotManager $slots)
    {
    }

    /** @return array{recovered:int, orphans:int} */
    public function run(): array
    {
        $recovered = 0;
        $live = $this->asterisk->activeChannelIds(); // null => unknown (dry run / unreachable)

        // 1. Orphan slots whose attempt is already finalized.
        $orphans = DidSlot::whereIn('call_attempt_id', CallAttempt::where('finalized', true)->select('id'))->delete();

        // 2. Expired slots: confirm with Asterisk, otherwise close the attempt as a temporary failure.
        DidSlot::where('expires_at', '<', now())->get()->each(function (DidSlot $slot) use ($live, &$recovered) {
            $attempt = CallAttempt::find($slot->call_attempt_id);
            if (! $attempt || $attempt->finalized) {
                $slot->delete();
                $recovered++;

                return;
            }

            $stillLive = $live !== null && in_array($attempt->call_ref, $live, true);
            if ($stillLive) {
                $this->slots->touch($attempt);

                return;
            }

            // Asterisk says the call is gone (or unreachable for > 2x TTL): missed hangup / crashed worker.
            if ($live !== null || $slot->expires_at->lt(now()->subSeconds((int) config('broadcast.slot_ttl')))) {
                $this->life->finalize($attempt, CallStatus::TEMPORARY_FAILURE, ['hangup_cause' => 'RECONCILED']);
                $this->slots->release($attempt);
                $recovered++;
                Log::warning('Recovered stale DID slot', ['attempt' => $attempt->id, 'did' => $slot->did_id]);
            }
        });

        // 3. Attempts stuck in QUEUED (worker died before originate) with no slot activity.
        CallAttempt::where('status', CallStatus::QUEUED)->where('finalized', false)->where('created_at', '<', now()->subMinutes(5))
            ->each(function (CallAttempt $a) use (&$recovered) {
                $this->life->abortQueued($a, false);
                $recovered++;
            });

        // 4. Keep RUNNING campaigns moving (lost dispatch jobs, due retries).
        Campaign::where('status', CampaignStatus::RUNNING)->pluck('id')->each(fn ($id) => DispatchCampaignCalls::dispatch($id));

        // 5. Start scheduled campaigns that are due.
        Campaign::where('status', CampaignStatus::QUEUED)->where('scheduled_at', '<=', now())->each(fn ($c) => app(CampaignService::class)->startQueued($c));

        return ['recovered' => $recovered, 'orphans' => $orphans];
    }
}
