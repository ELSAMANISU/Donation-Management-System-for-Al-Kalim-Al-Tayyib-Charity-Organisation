<?php

namespace App\Policies;

use App\Enums\UserRole;
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

    private function isEligibleAdministrator(User $actor): bool
    {
        return $actor->is_active
            && ! $actor->must_change_password
            && $actor->hasAnyRole([UserRole::Admin, UserRole::SuperAdmin]);
    }
}
