<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class CreateSuperAdmin extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'user:create-super-admin
                            {--name= : The administrator name}
                            {--email= : The administrator email address}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new active super-administrator account';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $name = trim((string) ($this->option('name') ?? $this->ask('Name')));
        $email = Str::lower(trim((string) ($this->option('email') ?? $this->ask('Email'))));
        $password = (string) $this->secret('Password');
        $passwordConfirmation = (string) $this->secret('Confirm password');

        try {
            $validated = validator([
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'password_confirmation' => $passwordConfirmation,
            ], [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class)],
                'password' => ['required', 'confirmed', Password::defaults()],
            ])->validate();
        } catch (ValidationException $exception) {
            foreach ($exception->validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        DB::transaction(function () use ($validated): void {
            $user = new User;
            $user->name = $validated['name'];
            $user->email = $validated['email'];
            $user->password = Hash::make($validated['password']);
            $user->role = UserRole::SuperAdmin;
            $user->is_active = true;
            $user->disabled_at = null;
            $user->disabled_reason = null;
            $user->disabled_by = null;
            $user->save();
        });

        $this->info('Super-administrator account created successfully.');

        return self::SUCCESS;
    }
}
