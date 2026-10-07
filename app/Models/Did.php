<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Did extends Model
{
    protected $fillable = ['number', 'label', 'status', 'max_concurrent_calls', 'trunk'];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    public function slots(): HasMany
    {
        return $this->hasMany(DidSlot::class);
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isDeletable(): bool
    {
        return ! $this->campaigns()->exists() && ! $this->slots()->exists();
    }
}
