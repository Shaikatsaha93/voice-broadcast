<?php

namespace App\Policies;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\User;

class CampaignPolicy
{
    /** The owner may act on a campaign; so may a Super Admin (any) or an Admin (own + their users'). */
    private function owns(User $u, Campaign $c): bool
    {
        return $c->user_id === $u->id || ($u->isManager() && $u->canViewActivityOf($c->user));
    }

    public function view(User $u, Campaign $c): bool
    {
        return $c->user_id === $u->id || $u->canViewActivityOf($c->user);
    }

    public function update(User $u, Campaign $c): bool
    {
        return $this->owns($u, $c) && $c->status->isEditable();
    }

    public function submit(User $u, Campaign $c): bool
    {
        return $this->owns($u, $c) && in_array($c->status, [CampaignStatus::DRAFT, CampaignStatus::REJECTED], true);
    }

    /** Super Admin approves any campaign; an Admin approves their own and their users' campaigns. */
    public function approve(User $u, Campaign $c): bool
    {
        return $u->isSuperAdmin() || ($u->isAdmin() && ($c->user_id === $u->id || $u->canManageUser($c->user)));
    }

    public function control(User $u, Campaign $c): bool
    {
        return $this->owns($u, $c);
    }

    public function delete(User $u, Campaign $c): bool
    {
        return $this->owns($u, $c) && $c->status === CampaignStatus::DRAFT;
    }
}
