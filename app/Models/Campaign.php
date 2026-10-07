<?php

namespace App\Models;

use App\Enums\CampaignStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Campaign extends Model
{
    protected $fillable = ['user_id', 'did_id', 'audio_file_id', 'name', 'status', 'max_attempts', 'requested_concurrency', 'retry_delay_seconds', 'scheduled_at', 'submitted_at', 'approved_at', 'started_at', 'completed_at', 'rejection_reason'];

    protected function casts(): array
    {
        return [
            'status' => CampaignStatus::class,
            'scheduled_at' => 'datetime', 'submitted_at' => 'datetime', 'approved_at' => 'datetime',
            'started_at' => 'datetime', 'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function did(): BelongsTo
    {
        return $this->belongsTo(Did::class);
    }

    public function audio(): BelongsTo
    {
        return $this->belongsTo(AudioFile::class, 'audio_file_id');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(CampaignRecipient::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(CallAttempt::class);
    }

    public function imports(): HasMany
    {
        return $this->hasMany(NumberImport::class);
    }

    public function scopeVisibleTo($q, User $user)
    {
        return $user->isSuperAdmin() ? $q : $q->where('campaigns.user_id', $user->id);
    }
}
