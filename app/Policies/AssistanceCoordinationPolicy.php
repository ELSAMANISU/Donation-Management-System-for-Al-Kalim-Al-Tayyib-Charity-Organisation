<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\HelpApplication;
use App\Models\User;

class AssistanceCoordinationPolicy
{
    public function administer(User $actor, HelpApplication $application): bool
    {
        return UserRole::tryFrom((string) $actor->getRawOriginal('role')) !== null && $actor->is_active && ! $actor->must_change_password
            && ($actor->hasRole(UserRole::SuperAdmin)
                || ($actor->hasRole(UserRole::Admin) && $application->reviewed_by !== null && $application->reviewed_by === $actor->id));
    }

    public function applicant(User $actor, HelpApplication $application): bool
    {
        return UserRole::tryFrom((string) $actor->getRawOriginal('role')) !== null && $actor->is_active && ! $actor->must_change_password && $actor->hasRole(UserRole::User)
            && $application->applicant_id === $actor->id;
    }
}
