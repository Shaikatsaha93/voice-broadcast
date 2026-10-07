<?php

namespace App\Policies;

use App\Models\AudioFile;
use App\Models\User;

class AudioFilePolicy
{
    public function view(User $u, AudioFile $a): bool
    {
        return $u->isSuperAdmin() || $a->user_id === $u->id;
    }

    public function use(User $u, AudioFile $a): bool
    {
        return $a->user_id === $u->id;
    }
}
