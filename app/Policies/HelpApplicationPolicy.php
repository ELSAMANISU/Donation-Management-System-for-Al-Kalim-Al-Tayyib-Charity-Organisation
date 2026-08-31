<?php

namespace App\Policies;

use App\Enums\HelpApplicationStatus;
use App\Enums\UserRole;
use App\Models\HelpApplication;
use App\Models\User;

class HelpApplicationPolicy
{
    public function viewAny(User $actor): bool
    {
        return $this->isEligibleApplicant($actor);
    }

    public function create(User $actor): bool
    {
        return $this->isEligibleApplicant($actor);
    }

    public function view(User $actor, HelpApplication $application): bool
    {
        return $this->isEligibleApplicant($actor)
            && $application->applicant_id === $actor->getKey();
    }

    public function update(User $actor, HelpApplication $application): bool
    {
        return $this->view($actor, $application)
            && $application->status === HelpApplicationStatus::Draft
            && $application->open_slot === true;
    }

    private function isEligibleApplicant(User $actor): bool
    {
        return $actor->is_active
            && ! $actor->must_change_password
            && $actor->hasRole(UserRole::User);
    }
}
