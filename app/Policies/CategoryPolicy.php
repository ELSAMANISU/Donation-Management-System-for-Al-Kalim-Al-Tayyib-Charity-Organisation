<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Category;
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

    public function update(User $actor, Category $category): bool
    {
        return $this->isActiveAdministrator($actor) && ! $category->trashed();
    }

    public function delete(User $actor, Category $category): bool
    {
        return $this->isActiveAdministrator($actor) && ! $category->trashed();
    }

    public function restore(User $actor, Category $category): bool
    {
        return $this->isActiveAdministrator($actor) && $category->trashed();
    }

    private function isActiveAdministrator(User $actor): bool
    {
        return $actor->is_active
            && $actor->hasAnyRole([UserRole::Admin, UserRole::SuperAdmin]);
    }
}
