<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use Notifiable;

    protected $fillable = ['name', 'email', 'password', 'status', 'valid_until', 'rate_per_pulse', 'pulse_seconds', 'balance', 'created_by'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return ['email_verified_at' => 'datetime', 'password' => 'hashed', 'valid_until' => 'date'];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    /** The Super Admin / Admin who created this account (and therefore supervises it). */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(self::class, 'created_by');
    }

    public function dids(): BelongsToMany
    {
        return $this->belongsToMany(Did::class)->withTimestamps();
    }

    /** Active DIDs this user may run campaigns on: assigned ones, plus (managers) the DIDs created in their own tree, (System Admin) all. */
    public function usableDids()
    {
        return match (true) {
            $this->isSystemAdmin() => Did::query()->where('status', 'active'),
            $this->isManager() => Did::query()->where('status', 'active')->where(fn ($w) => $w->whereIn('created_by', $this->visibleOwnerIds())->orWhereIn('dids.id', $this->dids()->select('dids.id'))),
            default => $this->dids()->where('status', 'active'),
        };
    }

    /** An Admin account with a call charge and balance (given by the Super Admin). */
    public function hasWallet(): bool
    {
        return $this->isAdmin() && $this->rate_per_pulse !== null;
    }

    /**
     * The Admin whose balance pays for this person's calls: the Admin themself, or the Admin a User belongs to.
     * Null when nobody is billed (System / Super Admin campaigns, or an Admin without a wallet).
     */
    public function billingAdmin(): ?self
    {
        $admin = match (true) {
            $this->isAdmin() => $this,
            $this->isNormalUser() => $this->creator?->isAdmin() ? $this->creator : null,
            default => null,
        };

        return $admin?->hasWallet() ? $admin : null;
    }

    /** Same rule as a DID: the balance must cover one more pulse on top of the calls already in flight (rate 0 = never blocked). */
    public function canAffordAnotherCall(int $inFlight = 0): bool
    {
        $rate = (float) $this->rate_per_pulse;

        return $rate <= 0 || (float) $this->balance + 1e-9 >= $rate * ($inFlight + 1);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(UserTransaction::class);
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }

    public function hasRole(string $role): bool
    {
        return $this->roles->contains('name', $role);
    }

    public function isSystemAdmin(): bool
    {
        return $this->hasRole(Role::SYSTEM_ADMIN);
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(Role::SUPER_ADMIN);
    }

    public function isAdmin(): bool
    {
        return $this->hasRole(Role::ADMIN);
    }

    public function isNormalUser(): bool
    {
        return $this->hasRole(Role::USER);
    }

    /** System Admin, Super Admin or Admin: supervises other people's work and may also run campaigns. */
    public function isManager(): bool
    {
        return $this->isSystemAdmin() || $this->isSuperAdmin() || $this->isAdmin();
    }

    /** Roles this user may give when creating / editing accounts: one level below, or equal for the System Admin. */
    public function assignableRoles(): array
    {
        return match (true) {
            $this->isSystemAdmin() => [Role::USER, Role::ADMIN, Role::SUPER_ADMIN, Role::SYSTEM_ADMIN],
            $this->isSuperAdmin() => [Role::USER, Role::ADMIN],
            $this->isAdmin() => [Role::USER],
            default => [],
        };
    }

    /** Ids of every account below this one: the people they created, and the people those created, and so on. */
    public function descendantIds(): array
    {
        $seen = [$this->id];
        $frontier = [$this->id];
        $found = [];
        while ($frontier) {
            $frontier = static::whereIn('created_by', $frontier)->whereNotIn('id', $seen)->pluck('id')->all();
            $seen = array_merge($seen, $frontier);
            $found = array_merge($found, $frontier);
        }

        return $found;
    }

    /** This account plus everyone below it. */
    public function visibleOwnerIds(): array
    {
        return array_merge([$this->id], $this->descendantIds());
    }

    /** System Admin manages everyone; a Super Admin / Admin manages the accounts below them (and a Super Admin their own account). */
    public function canManageUser(self $target): bool
    {
        return $this->isSystemAdmin()
            || ($this->isSuperAdmin() && $target->id === $this->id)
            || ($this->isManager() && in_array($target->id, $this->descendantIds(), true));
    }

    /** Whose work this user may see: System Admin everyone, Super Admin / Admin themself + everyone below, User only themself. */
    public function canViewActivityOf(self $owner): bool
    {
        return match (true) {
            $this->isSystemAdmin() => true,
            $this->isManager() => in_array($owner->id, $this->visibleOwnerIds(), true),
            default => $owner->id === $this->id,
        };
    }

    /** Subquery: ids of the accounts below this one. */
    public function managedUserIds()
    {
        return static::whereIn('users.id', $this->descendantIds())->select('users.id');
    }

    /** Restrict a query to rows owned (via $column) by someone this user may see. */
    public function limitToVisibleOwners($query, string $column)
    {
        return match (true) {
            $this->isSystemAdmin() => $query,
            $this->isManager() => $query->whereIn($column, $this->visibleOwnerIds()),
            default => $query->where($column, $this->id),
        };
    }

    /** True once the validity date (last valid day) is over. */
    public function isExpired(): bool
    {
        return $this->valid_until !== null && $this->valid_until->endOfDay()->isPast();
    }

    /** Admin and User accounts need a validity date; System / Super Admin accounts do not expire. */
    public static function roleNeedsValidity(?string $role): bool
    {
        return in_array($role, [Role::ADMIN, Role::USER], true);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function hasPermission(string $permission): bool
    {
        return $this->roles->loadMissing('permissions')->flatMap->permissions->contains('name', $permission);
    }
}
