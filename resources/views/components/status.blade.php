@props(['value'])
@php
    $v = strtoupper((string) $value);
    $tone = match (true) {
        in_array($v, ['ANSWERED', 'COMPLETED', 'APPROVED', 'PLAYBACK_COMPLETED', 'READY', 'ACTIVE']) => 'badge-success',
        in_array($v, ['RUNNING', 'DIALING', 'RINGING', 'PLAYBACK_STARTED']) => 'badge-info',
        in_array($v, ['PENDING', 'PENDING_APPROVAL', 'QUEUED', 'RETRY_PENDING', 'PAUSED', 'PROCESSING', 'NO_ANSWER', 'BUSY']) => 'badge-warning',
        in_array($v, ['FAILED', 'REJECTED', 'CANCELLED', 'EXHAUSTED', 'INVALID_NUMBER', 'TEMPORARY_FAILURE', 'INACTIVE', 'ERROR']) => 'badge-error',
        default => 'badge-neutral',
    };
@endphp
<span class="badge badge-soft badge-sm font-semibold {{ $tone }}">{{ str_replace('_', ' ', $v) }}</span>
