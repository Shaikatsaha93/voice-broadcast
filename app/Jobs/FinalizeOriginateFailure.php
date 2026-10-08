<?php

namespace App\Jobs;

use App\Models\CallAttempt;
use App\Services\Asterisk\AsteriskEventProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Asterisk reports a failed originate (OriginateResponse "Failure") with only a coarse reason code.
 * The real hangup cause (declined, busy, ...) arrives in the Hangup event a moment later, so the
 * attempt is given a few seconds to be finalized by that event; if it is still open, this job
 * finalizes it from the coarse reason.
 */
class FinalizeOriginateFailure implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $attemptId, public string $reason)
    {
    }

    public function handle(AsteriskEventProcessor $events): void
    {
        $attempt = CallAttempt::find($this->attemptId);
        if ($attempt && ! $attempt->finalized) {
            $events->finalizeOriginateFailure($attempt, $this->reason);
        }
    }
}
