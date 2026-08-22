<?php

namespace Tests\Feature\Console;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateSuperAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'SecurePassword123!';

    public function test_it_creates_an_active_super_admin_using_hidden_password_prompts(): void
    {
        $this->artisan('user:create-super-admin')
            ->expectsQuestion('Name', 'Initial Administrator')
            ->expectsQuestion('Email', '  ADMIN@EXAMPLE.COM  ')
            ->expectsQuestion('Password', self::PASSWORD)
            ->expectsQuestion('Confirm password', self::PASSWORD)
            ->doesntExpectOutputToContain(self::PASSWORD)
            ->expectsOutput('Super-administrator account created successfully.')
            ->assertSuccessful();

        $user = User::query()->sole();

        $this->assertSame('Initial Administrator', $user->name);
        $this->assertSame('admin@example.com', $user->email);
        $this->assertSame(UserRole::SuperAdmin, $user->role);
        $this->assertTrue($user->is_active);
        $this->assertNull($user->disabled_at);
        $this->assertNull($user->disabled_reason);
        $this->assertNull($user->disabled_by);
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
    }

    public function test_duplicate_email_is_rejected_without_modifying_or_promoting_existing_user(): void
    {
        $existingUser = User::factory()->user()->create([
            'name' => 'Existing User',
            'email' => 'existing@example.com',
        ]);
        $originalPassword = $existingUser->password;

        $this->artisan('user:create-super-admin', [
            '--name' => 'Replacement Administrator',
            '--email' => 'existing@example.com',
        ])
            ->expectsQuestion('Password', self::PASSWORD)
            ->expectsQuestion('Confirm password', self::PASSWORD)
            ->expectsOutputToContain('email has already been taken')
            ->assertFailed();

        $this->assertDatabaseCount('users', 1);
        $existingUser->refresh();
        $this->assertSame('Existing User', $existingUser->name);
        $this->assertSame(UserRole::User, $existingUser->role);
        $this->assertSame($originalPassword, $existingUser->password);
    }

    public function test_invalid_email_is_rejected_without_creating_a_user(): void
    {
        $this->artisan('user:create-super-admin', [
            '--name' => 'Invalid Email Administrator',
            '--email' => 'not-an-email',
        ])
            ->expectsQuestion('Password', self::PASSWORD)
            ->expectsQuestion('Confirm password', self::PASSWORD)
            ->expectsOutputToContain('email field must be a valid email address')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_weak_password_is_rejected_without_creating_a_user(): void
    {
        $this->artisan('user:create-super-admin', [
            '--name' => 'Weak Password Administrator',
            '--email' => 'weak@example.com',
        ])
            ->expectsQuestion('Password', 'short')
            ->expectsQuestion('Confirm password', 'short')
            ->doesntExpectOutputToContain('short')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_unconfirmed_password_is_rejected_without_creating_a_user(): void
    {
        $this->artisan('user:create-super-admin', [
            '--name' => 'Unconfirmed Password Administrator',
            '--email' => 'unconfirmed@example.com',
        ])
            ->expectsQuestion('Password', self::PASSWORD)
            ->expectsQuestion('Confirm password', 'DifferentPassword123!')
            ->doesntExpectOutputToContain(self::PASSWORD)
            ->doesntExpectOutputToContain('DifferentPassword123!')
            ->expectsOutputToContain('password field confirmation does not match')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }
}
