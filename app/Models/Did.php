<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Did extends Model
{
    protected $fillable = ['number', 'label', 'status', 'max_concurrent_calls', 'balance', 'rate_per_pulse', 'pulse_seconds', 'trunk', 'created_by', 'sip_host', 'sip_port', 'sip_username', 'sip_password', 'sip_status', 'sip_status_detail', 'sip_checked_at'];

    protected $hidden = ['sip_password'];

    protected function casts(): array
    {
        return ['sip_password' => 'encrypted', 'sip_checked_at' => 'datetime'];
    }

    /** Super Admin sees every DID; an Admin only the DIDs they created. */
    public function scopeVisibleTo($q, User $user)
    {
        return match (true) {
            $user->isSuperAdmin() => $q,
            $user->isAdmin() => $q->where('dids.created_by', $user->id),
            default => $q->whereRaw('1 = 0'),
        };
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(DidTransaction::class);
    }

    /** True when the balance covers one more pulse on top of the calls already in flight (rate 0 = never blocked). */
    public function canAffordAnotherCall(int $inFlight = 0): bool
    {
        $rate = (float) $this->rate_per_pulse;

        return $rate <= 0 || (float) $this->balance + 1e-9 >= $rate * ($inFlight + 1);
    }

    public function hasSip(): bool
    {
        return filled($this->sip_host) && filled($this->sip_username);
    }

    /** Name of the PJSIP endpoint / registration objects generated for this DID. */
    public function sipName(): string
    {
        return 'sip-'.$this->id;
    }

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
