<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    /**
     * Without a target (list / create): Super Admin and Admin.
     * With a target: Super Admin any account, Admin only normal users.
     */
    public function manage(User $u, User|string|null $target = null): bool
    {
        if (! $target instanceof User) {
            return $u->isManager();
        }

        return $u->canManageUser($target);
    }
}
