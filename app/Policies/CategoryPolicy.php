<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

class CategoryPolicy
{
    public function viewAny(User $actor): bool
    {
        return $this->isActiveAdministrator($actor);
    }

    public function create(User $actor): bool
    {
        return $this->isActiveAdministrator($actor);
    }

    private function isActiveAdministrator(User $actor): bool
    {
        return $actor->is_active
            && $actor->hasAnyRole([UserRole::Admin, UserRole::SuperAdmin]);
    }
}
