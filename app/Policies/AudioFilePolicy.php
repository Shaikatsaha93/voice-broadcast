<?php

namespace App\Policies;

use App\Models\AudioFile;
use App\Models\User;

class AudioFilePolicy
{
    public function view(User $u, AudioFile $a): bool
    {
        return $a->user_id === $u->id || $u->canViewActivityOf($a->user);
    }

    public function use(User $u, AudioFile $a): bool
    {
        return $a->user_id === $u->id || ($u->isManager() && $u->canViewActivityOf($a->user));
    }

    /** Only admins / super admins, and only for audio they can see (their own or their users'). */
    public function delete(User $u, AudioFile $a): bool
    {
        return $u->isManager() && $this->view($u, $a);
    }
}
