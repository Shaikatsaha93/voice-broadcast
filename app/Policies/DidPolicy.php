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

    /** Using a DID in a campaign: must be assigned (admins use DIDs through the owner's assignment only). */
    public function use(User $u, Did $d): bool
    {
        return $d->isActive() && $u->dids()->whereKey($d->id)->exists();
    }

    public function manage(User $u, ?Did $d = null): bool
    {
        return $u->isSuperAdmin();
    }
}
