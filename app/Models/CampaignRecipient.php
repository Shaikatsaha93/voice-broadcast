<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CampaignRecipient extends Model
{
    protected $fillable = ['campaign_id', 'phone', 'status', 'attempts_count', 'next_attempt_at', 'final_result'];

    protected function casts(): array
    {
        return ['next_attempt_at' => 'datetime'];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(CallAttempt::class, 'recipient_id');
    }
}
