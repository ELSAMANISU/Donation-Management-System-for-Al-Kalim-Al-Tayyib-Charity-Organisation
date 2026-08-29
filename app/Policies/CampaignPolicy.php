<?php

namespace App\Policies;

use App\Enums\CampaignStatus;
use App\Enums\UserRole;
use App\Models\Campaign;
use App\Models\User;

class CampaignPolicy
{
    public function viewAny(User $actor): bool
    {
        return $this->isEligibleAdministrator($actor);
    }

    public function create(User $actor): bool
    {
        return $this->isEligibleAdministrator($actor);
    }

    public function update(User $actor, Campaign $campaign): bool
    {
        return $this->isEligibleAdministrator($actor)
            && ! $campaign->trashed()
            && $campaign->status === CampaignStatus::Draft;
    }

    private function isEligibleAdministrator(User $actor): bool
    {
        return $actor->is_active
            && ! $actor->must_change_password
            && $actor->hasAnyRole([UserRole::Admin, UserRole::SuperAdmin]);
    }
}
