<?php

namespace App\Policies;

use App\Models\Did;
use App\Models\User;

class DidPolicy
{
    public function view(User $u, Did $d): bool
    {
        return $this->manage($u, $d) || $u->dids()->whereKey($d->id)->exists();
    }

    /** Using a DID in a campaign: must be assigned (Admins also use the DIDs they created, Super Admin any). */
    public function use(User $u, Did $d): bool
    {
        return $d->isActive() && $u->usableDids()->whereKey($d->id)->exists();
    }

    /** List / create: Super Admin and Admin. A specific DID: System Admin, or the Super Admin / Admin whose tree it was created in. */
    public function manage(User $u, Did|string|null $d = null): bool
    {
        if (! $d instanceof Did) {
            return $u->isManager();
        }

        return $u->isSystemAdmin() || ($u->isManager() && in_array($d->created_by, $u->visibleOwnerIds(), true));
    }
}
