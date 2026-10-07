<?php

namespace App\Enums;

enum CallStatus: string
{
    case PENDING = 'PENDING';
    case QUEUED = 'QUEUED';
    case DIALING = 'DIALING';
    case RINGING = 'RINGING';
    case ANSWERED = 'ANSWERED';
    case PLAYBACK_STARTED = 'PLAYBACK_STARTED';
    case PLAYBACK_COMPLETED = 'PLAYBACK_COMPLETED';
    case NO_ANSWER = 'NO_ANSWER';
    case BUSY = 'BUSY';
    case FAILED = 'FAILED';
    case CANCELLED = 'CANCELLED';
    case RETRY_PENDING = 'RETRY_PENDING';
    case EXHAUSTED = 'EXHAUSTED';
    case COMPLETED = 'COMPLETED';
    case TEMPORARY_FAILURE = 'TEMPORARY_FAILURE';
    case INVALID_NUMBER = 'INVALID_NUMBER';

    /** Monotonic rank: delayed / out-of-order events can never move a call backwards. */
    public function rank(): int
    {
        return match ($this) {
            self::PENDING => 0, self::QUEUED => 1, self::DIALING => 2, self::RINGING => 3,
            self::ANSWERED => 4, self::PLAYBACK_STARTED => 5, self::PLAYBACK_COMPLETED => 6,
            default => 10,
        };
    }

    public function isTerminal(): bool
    {
        return $this->rank() >= 10;
    }

    public function isRetryable(): bool
    {
        return in_array($this, [self::NO_ANSWER, self::BUSY, self::TEMPORARY_FAILURE], true);
    }
}
