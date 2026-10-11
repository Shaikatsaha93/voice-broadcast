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
        return $a->user_id === $u->id || $this->reviewer($u, $a);
    }

    /** Replace the file: only a System Admin / Super Admin for audio in their tree. Admins and Users ask their Super Admin. */
    public function replace(User $u, AudioFile $a): bool
    {
        return $this->reviewer($u, $a);
    }

    /** Download the uploaded original (to convert it): System Admin / Super Admin only. */
    public function download(User $u, AudioFile $a): bool
    {
        return $this->reviewer($u, $a);
    }

    /** System Admin / Super Admin for audio they can see; an Admin has no review power over other people's audio. */
    private function reviewer(User $u, AudioFile $a): bool
    {
        return ($u->isSystemAdmin() || $u->isSuperAdmin()) && $u->canViewActivityOf($a->user);
    }

    /** Only System Admin / Super Admin, and only for audio in their tree. An Admin cannot delete audio. */
    public function delete(User $u, AudioFile $a): bool
    {
        return $this->reviewer($u, $a);
    }
}
