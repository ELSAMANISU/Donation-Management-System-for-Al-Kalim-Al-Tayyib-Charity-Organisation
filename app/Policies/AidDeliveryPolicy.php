<?php

namespace App\Policies;

use App\Models\HelpApplication;
use App\Models\User;

class AidDeliveryPolicy
{
    public function administer(User $actor, HelpApplication $application): bool
    {
        return app(AssistanceCoordinationPolicy::class)->administer($actor, $application);
    }

    public function applicant(User $actor, HelpApplication $application): bool
    {
        return app(AssistanceCoordinationPolicy::class)->applicant($actor, $application);
    }
}
