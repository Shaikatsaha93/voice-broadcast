<?php

namespace App\Policies;

use App\Models\Did;
use App\Models\User;

class DidPolicy
{
    public function view(User $u, Did $d): bool
    {
        return $u->isSuperAdmin() || $u->dids()->whereKey($d->id)->exists();
    }

    /** Using a DID in a campaign: must be assigned (Admins also use the DIDs they created, Super Admin any). */
    public function use(User $u, Did $d): bool
    {
        return $d->isActive() && $u->usableDids()->whereKey($d->id)->exists();
    }

    /** List / create: Super Admin and Admin. A specific DID: Super Admin, or the Admin who created it. */
    public function manage(User $u, Did|string|null $d = null): bool
    {
        if (! $d instanceof Did) {
            return $u->isManager();
        }

        return $u->isSuperAdmin() || ($u->isAdmin() && $d->created_by === $u->id);
    }
}
