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

    protected $fillable = ['name', 'email', 'password', 'status', 'created_by'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return ['email_verified_at' => 'datetime', 'password' => 'hashed'];
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

    /** Active DIDs this user may run campaigns on: assigned ones, plus (Admin) the DIDs they created or (Super Admin) all. */
    public function usableDids()
    {
        return match (true) {
            $this->isSuperAdmin() => Did::query()->where('status', 'active'),
            $this->isAdmin() => Did::query()->where('status', 'active')->where(fn ($w) => $w->where('created_by', $this->id)->orWhereIn('dids.id', $this->dids()->select('dids.id'))),
            default => $this->dids()->where('status', 'active'),
        };
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }

    public function hasRole(string $role): bool
    {
        return $this->roles->contains('name', $role);
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

    /** Super Admin or Admin: supervises other people's work instead of running campaigns. */
    public function isManager(): bool
    {
        return $this->isSuperAdmin() || $this->isAdmin();
    }

    /** Roles this user may give when creating / editing accounts. Admin: normal users only. */
    public function assignableRoles(): array
    {
        return match (true) {
            $this->isSuperAdmin() => [Role::USER, Role::ADMIN, Role::SUPER_ADMIN],
            $this->isAdmin() => [Role::USER],
            default => [],
        };
    }

    /** An Admin manages only the normal users they created; a Super Admin manages everyone. */
    public function canManageUser(self $target): bool
    {
        return $this->isSuperAdmin() || ($this->isAdmin() && $target->isNormalUser() && $target->created_by === $this->id);
    }

    /** Whose work this user may see: Super Admin everyone, Admin themself + their own users, User only themself. */
    public function canViewActivityOf(self $owner): bool
    {
        return match (true) {
            $this->isSuperAdmin() => true,
            $this->isAdmin() => $owner->id === $this->id || ($owner->isNormalUser() && $owner->created_by === $this->id),
            default => $owner->id === $this->id,
        };
    }

    /** Subquery: ids of the normal users this Admin created. */
    public function managedUserIds()
    {
        return static::where('created_by', $this->id)->whereHas('roles', fn ($r) => $r->where('name', Role::USER))->select('users.id');
    }

    /** Restrict a query to rows owned (via $column) by someone this user may see. */
    public function limitToVisibleOwners($query, string $column)
    {
        return match (true) {
            $this->isSuperAdmin() => $query,
            $this->isAdmin() => $query->where(fn ($w) => $w->where($column, $this->id)->orWhereIn($column, $this->managedUserIds())),
            default => $query->where($column, $this->id),
        };
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
