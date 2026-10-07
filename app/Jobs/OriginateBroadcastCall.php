<?php

namespace App\Jobs;

use App\Enums\CallStatus;
use App\Enums\CampaignStatus;
use App\Models\CallAttempt;
use App\Services\Asterisk\AsteriskService;
use App\Services\Calls\CallLifecycle;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class OriginateBroadcastCall implements ShouldQueue
{
    use Queueable;

    public int $tries = 1; // never re-originate automatically; reconciliation handles crashes

    public function __construct(public int $attemptId)
    {
    }

    public function handle(AsteriskService $asterisk, CallLifecycle $life): void
    {
        // Idempotency: atomically move QUEUED -> DIALING; a duplicate job loses the race.
        $attempt = DB::transaction(function () {
            $a = CallAttempt::whereKey($this->attemptId)->lockForUpdate()->first();
            if (! $a || $a->finalized || $a->status !== CallStatus::QUEUED) {
                return null;
            }
            if ($a->campaign->status !== CampaignStatus::RUNNING) {
                return $a->setAttribute('_abort', true);
            }
            $a->update(['status' => CallStatus::DIALING, 'status_rank' => CallStatus::DIALING->rank(), 'dialed_at' => now()]);

            return $a;
        });

        if (! $attempt) {
            return;
        }
        if ($attempt->getAttribute('_abort')) {
            $life->abortQueued($attempt, $attempt->campaign->status === CampaignStatus::CANCELLED);

            return;
        }

        if (! $asterisk->originate($attempt)) {
            $life->finalize($attempt, CallStatus::TEMPORARY_FAILURE, ['hangup_cause' => 'ORIGINATE_FAILED']);
        }
    }
}
