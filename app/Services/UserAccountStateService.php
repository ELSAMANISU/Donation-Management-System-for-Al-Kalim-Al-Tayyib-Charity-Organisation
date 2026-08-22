<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class UserAccountStateService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function disable(User $actor, User $target, string $reason, Request $request): User
    {
        return DB::transaction(function () use ($actor, $target, $reason, $request): User {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($target->getKey());

            Gate::forUser($actor)->authorize('changeAccountState', $lockedUser);

            if (! $lockedUser->is_active) {
                throw ValidationException::withMessages([
                    'user' => 'This account is already disabled. / هذا الحساب معطّل بالفعل.',
                ]);
            }

            if ($lockedUser->hasRole(UserRole::SuperAdmin)) {
                $activeSuperAdminIds = User::query()
                    ->where('role', UserRole::SuperAdmin)
                    ->where('is_active', true)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->pluck('id');

                if ($activeSuperAdminIds->count() <= 1) {
                    throw ValidationException::withMessages([
                        'user' => 'The last active super administrator cannot be disabled. / لا يمكن تعطيل آخر مسؤول أعلى نشط.',
                    ]);
                }
            }

            $this->rejectSelfService($actor, $lockedUser, 'disabled');

            $oldValues = $this->stateValues($lockedUser);
            $disabledAt = now();

            $lockedUser->is_active = false;
            $lockedUser->disabled_at = $disabledAt;
            $lockedUser->disabled_reason = $reason;
            $lockedUser->disabled_by = $actor->getKey();
            $lockedUser->save();

            $this->deleteDatabaseSessions($lockedUser);

            $this->auditLogger->log(
                action: 'user.disabled',
                actor: $actor,
                subject: $lockedUser,
                oldValues: $oldValues,
                newValues: $this->stateValues($lockedUser),
                request: $request,
            );

            return $lockedUser;
        });
    }

    public function reactivate(User $actor, User $target, Request $request): User
    {
        return DB::transaction(function () use ($actor, $target, $request): User {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($target->getKey());

            Gate::forUser($actor)->authorize('changeAccountState', $lockedUser);
            $this->rejectSelfService($actor, $lockedUser, 'reactivated');

            if ($lockedUser->is_active) {
                throw ValidationException::withMessages([
                    'user' => 'This account is already active. / هذا الحساب مفعّل بالفعل.',
                ]);
            }

            $oldValues = $this->stateValues($lockedUser);

            $lockedUser->is_active = true;
            $lockedUser->disabled_at = null;
            $lockedUser->disabled_reason = null;
            $lockedUser->disabled_by = null;
            $lockedUser->save();

            $this->auditLogger->log(
                action: 'user.reactivated',
                actor: $actor,
                subject: $lockedUser,
                oldValues: $oldValues,
                newValues: $this->stateValues($lockedUser),
                request: $request,
            );

            return $lockedUser;
        });
    }

    private function rejectSelfService(User $actor, User $target, string $action): void
    {
        if ($actor->is($target)) {
            throw ValidationException::withMessages([
                'user' => "You cannot have your own account {$action} through this action. / لا يمكنك تغيير حالة حسابك بنفسك من خلال هذا الإجراء.",
            ]);
        }
    }

    /**
     * @return array<string, bool|int|string|null>
     */
    private function stateValues(User $user): array
    {
        return [
            'is_active' => $user->is_active,
            'disabled_at' => $user->disabled_at?->toISOString(),
            'disabled_reason' => $user->disabled_reason,
            'disabled_by' => $user->disabled_by,
        ];
    }

    private function deleteDatabaseSessions(User $user): void
    {
        $table = (string) config('session.table', 'sessions');

        if (Schema::hasTable($table)) {
            DB::table($table)->where('user_id', $user->getKey())->delete();
        }
    }
}
