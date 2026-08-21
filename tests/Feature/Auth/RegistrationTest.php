<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_new_users_can_register(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect('/');

        $user = User::where('email', 'test@example.com')->firstOrFail();

        $this->assertSame(UserRole::User, $user->role);
        $this->assertTrue($user->is_active);
    }

    public function test_admin_role_injection_still_creates_a_normal_active_user(): void
    {
        $this->post('/register', [
            'name' => 'Test User',
            'email' => 'admin-injection@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'role' => UserRole::Admin->value,
        ]);

        $user = User::where('email', 'admin-injection@example.com')->firstOrFail();

        $this->assertSame(UserRole::User, $user->role);
        $this->assertTrue($user->is_active);
    }

    public function test_super_admin_and_account_state_injection_still_creates_a_normal_active_user(): void
    {
        $disablingUser = User::factory()->admin()->create();

        $this->post('/register', [
            'name' => 'Test User',
            'email' => 'state-injection@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'role' => UserRole::SuperAdmin->value,
            'is_active' => false,
            'disabled_at' => now(),
            'disabled_reason' => 'Injected reason',
            'disabled_by' => $disablingUser->id,
        ]);

        $user = User::where('email', 'state-injection@example.com')->firstOrFail();

        $this->assertSame(UserRole::User, $user->role);
        $this->assertTrue($user->is_active);
        $this->assertNull($user->disabled_at);
        $this->assertNull($user->disabled_reason);
        $this->assertNull($user->disabled_by);
    }
}
