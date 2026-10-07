<?php

namespace App\Policies;

use App\Models\CallAttempt;
use App\Models\User;

class CallAttemptPolicy
{
    public function view(User $u, CallAttempt $a): bool
    {
        return $u->isSuperAdmin() || $a->user_id === $u->id;
    }
}
