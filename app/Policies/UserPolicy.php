<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

class UserPolicy
{
    /**
     * Determine whether the actor may access user management.
     */
    public function viewAny(User $actor): bool
    {
        return $actor->hasAnyRole([UserRole::Admin, UserRole::SuperAdmin]);
    }

    /**
     * Determine whether the actor may view a user account.
     */
    public function view(User $actor, User $user): bool
    {
        return $actor->hasAnyRole([UserRole::Admin, UserRole::SuperAdmin]);
    }

    /**
     * Determine whether the actor may change the account's active state.
     */
    public function changeAccountState(User $actor, User $user): bool
    {
        return $actor->hasRole(UserRole::SuperAdmin)
            || ($actor->hasRole(UserRole::Admin) && $user->hasRole(UserRole::User));
    }

    /**
     * Determine whether the actor may create an administrator account.
     */
    public function createAdministrator(User $actor): bool
    {
        return $actor->hasRole(UserRole::SuperAdmin);
    }

    /**
     * Determine whether the actor may access administrator management.
     */
    public function manageAdministrators(User $actor): bool
    {
        return $actor->hasRole(UserRole::SuperAdmin);
    }

    /**
     * Determine whether the actor may manage an administrator account.
     */
    public function manageAdministrator(User $actor, User $user): bool
    {
        return $actor->hasRole(UserRole::SuperAdmin)
            && $user->hasAnyRole([UserRole::Admin, UserRole::SuperAdmin]);
    }

    /**
     * Determine whether the actor may change a user's role.
     */
    public function changeRole(User $actor, User $user): bool
    {
        return $actor->hasRole(UserRole::SuperAdmin);
    }
}
