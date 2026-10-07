<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AudioFile extends Model
{
    protected $fillable = ['user_id', 'original_name', 'path', 'normalized_path', 'mime', 'size', 'duration', 'status', 'error'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isReady(): bool
    {
        return $this->status === 'READY';
    }
}
