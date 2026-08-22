<?php

namespace App\Services;

use App\Data\CreatedAdministrator;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdministratorCreationService
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly TemporaryPasswordGenerator $passwordGenerator,
    ) {}

    public function create(User $actor, string $name, string $email, Request $request): CreatedAdministrator
    {
        $normalizedEmail = Str::lower(trim($email));
        $temporaryPassword = $this->passwordGenerator->generate();

        try {
            return DB::transaction(function () use ($actor, $name, $normalizedEmail, $temporaryPassword, $request): CreatedAdministrator {
                $lockedActor = User::query()->lockForUpdate()->findOrFail($actor->getKey());

                Gate::forUser($lockedActor)->authorize('createAdministrator', User::class);

                if (! $lockedActor->is_active) {
                    abort(403);
                }

                if (User::query()->whereRaw('LOWER(email) = ?', [$normalizedEmail])->exists()) {
                    $this->throwDuplicateEmailValidation();
                }

                $administrator = new User;
                $administrator->name = trim($name);
                $administrator->email = $normalizedEmail;
                $administrator->password = Hash::make($temporaryPassword);
                $administrator->role = UserRole::Admin;
                $administrator->is_active = true;
                $administrator->must_change_password = true;
                $administrator->password_changed_at = null;
                $administrator->disabled_at = null;
                $administrator->disabled_reason = null;
                $administrator->disabled_by = null;
                $administrator->save();

                $this->auditLogger->log(
                    action: 'administrator.created',
                    actor: $lockedActor,
                    subject: $administrator,
                    newValues: [
                        'role' => $administrator->role->value,
                        'is_active' => $administrator->is_active,
                        'must_change_password' => $administrator->must_change_password,
                    ],
                    request: $request,
                );

                return new CreatedAdministrator($administrator, $temporaryPassword);
            });
        } catch (QueryException $exception) {
            if ($this->isDuplicateEmailViolation($exception)) {
                $this->throwDuplicateEmailValidation();
            }

            throw $exception;
        }
    }

    private function isDuplicateEmailViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);
        $message = Str::lower($exception->getMessage());

        return in_array($sqlState, ['23000', '23505'], true)
            && (in_array($driverCode, [19, 1062], true) || str_contains($message, 'unique'))
            && str_contains($message, 'email');
    }

    /**
     * @return never
     */
    private function throwDuplicateEmailValidation(): void
    {
        throw ValidationException::withMessages([
            'email' => 'An account with this email address already exists.',
        ]);
    }
}
