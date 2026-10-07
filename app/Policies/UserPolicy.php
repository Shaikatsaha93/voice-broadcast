<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function manage(User $u, ?User $target = null): bool
    {
        return $u->isSuperAdmin();
    }
}
