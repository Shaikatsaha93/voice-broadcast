<?php

namespace App\Policies;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\User;

class CampaignPolicy
{
    private function owns(User $u, Campaign $c): bool
    {
        return $u->isSuperAdmin() || $c->user_id === $u->id;
    }

    public function view(User $u, Campaign $c): bool
    {
        return $this->owns($u, $c);
    }

    public function update(User $u, Campaign $c): bool
    {
        return $c->user_id === $u->id && $c->status->isEditable();
    }

    public function submit(User $u, Campaign $c): bool
    {
        return $c->user_id === $u->id && in_array($c->status, [CampaignStatus::DRAFT, CampaignStatus::REJECTED], true);
    }

    public function approve(User $u, Campaign $c): bool
    {
        return $u->isSuperAdmin();
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
