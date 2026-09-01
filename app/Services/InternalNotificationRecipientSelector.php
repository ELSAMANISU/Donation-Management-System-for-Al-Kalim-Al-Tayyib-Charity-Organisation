<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

final class InternalNotificationRecipientSelector
{
    /**
     * This transaction-only API freezes eligible administrator rows in
     * deterministic lock order for a future domain transition.
     *
     * @return list<User>
     */
    public function lockedEligibleAdministrators(): array
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Notification recipient selection requires a transaction.');
        }

        return User::query()
            ->whereIn('role', [UserRole::Admin->value, UserRole::SuperAdmin->value])
            ->where('is_active', true)
            ->where('must_change_password', false)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->all();
    }
}
