<?php

namespace App\Policies;

use App\Models\User;

final class ReportPolicy
{
    public function viewReports(User $actor): bool
    {
        return $actor->is_active && ! $actor->must_change_password
            && in_array($actor->getRawOriginal('role'), ['admin', 'super_admin'], true);
    }
}
