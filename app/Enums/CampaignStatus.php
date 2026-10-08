<?php

namespace App\Enums;

enum CampaignStatus: string
{
    case DRAFT = 'DRAFT';
    case PENDING_APPROVAL = 'PENDING_APPROVAL';
    case APPROVED = 'APPROVED';
    case QUEUED = 'QUEUED';
    case RUNNING = 'RUNNING';
    case PAUSED = 'PAUSED';
    case COMPLETED = 'COMPLETED';
    case REJECTED = 'REJECTED';
    case CANCELLED = 'CANCELLED';
    case FAILED = 'FAILED';

    /** Explicit state machine: from => allowed targets. */
    public static function transitions(): array
    {
        return [
            'DRAFT' => ['PENDING_APPROVAL', 'CANCELLED'],
            'PENDING_APPROVAL' => ['APPROVED', 'REJECTED', 'CANCELLED', 'DRAFT'],
            'APPROVED' => ['QUEUED', 'RUNNING', 'CANCELLED'],
            'QUEUED' => ['RUNNING', 'PAUSED', 'CANCELLED'],
            'RUNNING' => ['PAUSED', 'COMPLETED', 'CANCELLED', 'FAILED'],
            'PAUSED' => ['RUNNING', 'CANCELLED'],
            'COMPLETED' => ['RUNNING'], 'CANCELLED' => ['RUNNING'], 'REJECTED' => [], 'FAILED' => [],
        ];
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to->value, self::transitions()[$this->value], true);
    }

    public function canDial(): bool
    {
        return $this === self::RUNNING;
    }

    public function isEditable(): bool
    {
        return in_array($this, [self::DRAFT, self::REJECTED], true);
    }
}
