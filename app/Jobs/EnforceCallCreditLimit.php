<?php

namespace App\Jobs;

use App\Models\CallAttempt;
use App\Services\Asterisk\AsteriskService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Hangs up an answered call that is still running when the credit reserved for it is used up
 * (A2Billing-style max call duration). The normal Hangup event then finalizes and bills it.
 */
class EnforceCallCreditLimit implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $attemptId)
    {
    }

    public function handle(AsteriskService $asterisk): void
    {
        $a = CallAttempt::find($this->attemptId);
        if (! $a || $a->finalized || ! $a->answered_at || ! $a->max_seconds) {
            return;
        }

        Log::warning('Call hung up at its credit limit', ['attempt' => $a->id, 'max_seconds' => $a->max_seconds]);
        $asterisk->hangup($a->call_ref);
    }
}
