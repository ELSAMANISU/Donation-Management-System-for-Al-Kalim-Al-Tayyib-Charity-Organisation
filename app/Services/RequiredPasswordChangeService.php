<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class RequiredPasswordChangeService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function change(User $authenticatedUser, string $currentPassword, string $newPassword, Request $request): User
    {
        $user = DB::transaction(function () use ($authenticatedUser, $currentPassword, $newPassword, $request): User {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($authenticatedUser->getKey());

            if (! $lockedUser->is_active) {
                throw ValidationException::withMessages([
                    'current_password' => trans('auth.failed'),
                ]);
            }

            if (! $lockedUser->must_change_password) {
                throw ValidationException::withMessages([
                    'password' => 'A required password change is not pending for this account.',
                ]);
            }

            if (! Hash::check($currentPassword, $lockedUser->password)) {
                throw ValidationException::withMessages([
                    'current_password' => __('The password is incorrect.'),
                ]);
            }

            $oldValues = $this->auditState($lockedUser);

            $lockedUser->password = Hash::make($newPassword);
            $lockedUser->must_change_password = false;
            $lockedUser->password_changed_at = now();
            $lockedUser->save();

            $this->auditLogger->log(
                action: 'account.initial_password_changed',
                actor: $lockedUser,
                subject: $lockedUser,
                oldValues: $oldValues,
                newValues: $this->auditState($lockedUser),
                request: $request,
            );

            return $lockedUser;
        });

        $request->session()->regenerate();

        return $user;
    }

    /**
     * @return array{must_change_password: bool, password_changed_at: ?string}
     */
    private function auditState(User $user): array
    {
        return [
            'must_change_password' => $user->must_change_password,
            'password_changed_at' => $user->password_changed_at?->toISOString(),
        ];
    }
}
