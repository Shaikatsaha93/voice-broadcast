<?php

namespace App\Models;

use App\Enums\CallStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CallAttempt extends Model
{
    protected $fillable = ['call_ref', 'campaign_id', 'recipient_id', 'did_id', 'user_id', 'phone', 'attempt_no', 'status', 'status_rank', 'asterisk_uniqueid', 'asterisk_linkedid', 'channel', 'hangup_cause', 'dial_status', 'dialed_at', 'answered_at', 'ended_at', 'duration', 'billsec', 'finalized'];

    protected function casts(): array
    {
        return ['status' => CallStatus::class, 'dialed_at' => 'datetime', 'answered_at' => 'datetime', 'ended_at' => 'datetime', 'finalized' => 'boolean'];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(CampaignRecipient::class, 'recipient_id');
    }

    public function did(): BelongsTo
    {
        return $this->belongsTo(Did::class);
    }

    public function slot(): HasOne
    {
        return $this->hasOne(DidSlot::class);
    }

    public function scopeVisibleTo($q, User $user)
    {
        return $user->isSuperAdmin() ? $q : $q->where('call_attempts.user_id', $user->id);
    }
}
