<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class RequiredPasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_users_with_false_flag_are_unaffected(): void
    {
        $user = User::factory()->create();

        $this->post('/login', $this->credentials($user))
            ->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
        $this->get('/')->assertOk();
    }

    public function test_flagged_users_of_every_role_are_redirected_to_required_change_after_login(): void
    {
        foreach ([UserRole::User, UserRole::Admin, UserRole::SuperAdmin] as $role) {
            $user = User::factory()->mustChangePassword()->create(['role' => $role]);

            $this->post('/login', $this->credentials($user))
                ->assertRedirect(route('password.change.required.edit'));

            $this->assertAuthenticatedAs($user);
            $this->post(route('logout'));
        }
    }

    public function test_flagged_user_is_redirected_from_ordinary_web_pages(): void
    {
        $user = User::factory()->mustChangePassword()->create();

        foreach (['/', '/dashboard', '/admin', '/profile'] as $path) {
            $this->actingAs($user)
                ->get($path)
                ->assertRedirect(route('password.change.required.edit'));
        }
    }

    public function test_required_change_form_and_logout_remain_accessible(): void
    {
        $user = User::factory()->mustChangePassword()->create();

        $this->actingAs($user)
            ->get(route('password.change.required.edit'))
            ->assertOk()
            ->assertSee('A temporary password is in use')
            ->assertSeeText('Change password / تغيير كلمة المرور')
            ->assertSee('bg-indigo-600')
            ->assertSee('كلمة مرور مؤقتة');

        $this->post(route('logout'))->assertRedirect('/');
        $this->assertGuest();
    }

    public function test_guest_cannot_access_required_change_form(): void
    {
        $this->get(route('password.change.required.edit'))
            ->assertRedirect(route('login'));
    }

    public function test_disabled_flagged_account_cannot_login_or_access_form(): void
    {
        $user = User::factory()->mustChangePassword()->disabled()->create();

        $this->post('/login', $this->credentials($user))
            ->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->actingAs($user)
            ->get(route('password.change.required.edit'))
            ->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_wrong_current_password_is_rejected(): void
    {
        $user = User::factory()->mustChangePassword()->create();

        $this->actingAs($user)
            ->from(route('password.change.required.edit'))
            ->patch(route('password.change.required.update'), $this->changePayload([
                'current_password' => 'wrong-password',
            ]))
            ->assertRedirect(route('password.change.required.edit'))
            ->assertSessionHasErrors('current_password');

        $this->assertTrue($user->refresh()->must_change_password);
    }

    public function test_weak_unconfirmed_and_reused_passwords_are_rejected(): void
    {
        $user = User::factory()->mustChangePassword()->create();

        $invalidPayloads = [
            $this->changePayload(['password' => 'short', 'password_confirmation' => 'short']),
            $this->changePayload(['password_confirmation' => 'different-password']),
            $this->changePayload(['password' => 'password', 'password_confirmation' => 'password']),
        ];

        foreach ($invalidPayloads as $payload) {
            $this->actingAs($user)
                ->patch(route('password.change.required.update'), $payload)
                ->assertSessionHasErrors('password');
        }

        $this->assertTrue($user->refresh()->must_change_password);
        $this->assertTrue(Hash::check('password', $user->password));
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_injected_privileged_fields_are_ignored(): void
    {
        $user = User::factory()->mustChangePassword()->create();

        $this->actingAs($user)->patch(route('password.change.required.update'), [
            ...$this->changePayload(),
            'role' => UserRole::SuperAdmin->value,
            'is_active' => false,
            'must_change_password' => true,
            'password_changed_at' => '2000-01-01 00:00:00',
            'password_hash' => 'injected-hash',
        ])->assertRedirect('/');

        $user->refresh();
        $this->assertSame(UserRole::User, $user->role);
        $this->assertTrue($user->is_active);
        $this->assertFalse($user->must_change_password);
        $this->assertNotSame('2000-01-01 00:00:00', $user->password_changed_at?->format('Y-m-d H:i:s'));
        $this->assertTrue(Hash::check('new-secure-password', $user->password));
    }

    public function test_successful_change_updates_state_regenerates_session_and_redirects_by_role(): void
    {
        foreach ([UserRole::User, UserRole::Admin, UserRole::SuperAdmin] as $role) {
            $user = User::factory()->mustChangePassword()->create(['role' => $role]);
            $this->actingAs($user)->get(route('password.change.required.edit'));
            $oldSessionId = session()->getId();

            $response = $this->patch(route('password.change.required.update'), $this->changePayload());

            $response->assertRedirect($role === UserRole::User ? '/' : route('admin.dashboard'));
            $this->assertAuthenticatedAs($user);
            $this->assertNotSame($oldSessionId, session()->getId());

            $user->refresh();
            $this->assertFalse($user->must_change_password);
            $this->assertNotNull($user->password_changed_at);
            $this->assertTrue(Hash::check('new-secure-password', $user->password));

            $this->post(route('logout'));
        }
    }

    public function test_old_password_fails_and_new_password_works_after_success(): void
    {
        $user = User::factory()->mustChangePassword()->create();

        $this->actingAs($user)
            ->patch(route('password.change.required.update'), $this->changePayload());
        $this->post(route('logout'));

        $this->post('/login', $this->credentials($user))
            ->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->post('/login', $this->credentials($user, 'new-secure-password'))
            ->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
    }

    public function test_successful_change_writes_privacy_safe_audit_entry(): void
    {
        $user = User::factory()->mustChangePassword()->create();

        $this->actingAs($user)->withHeader('User-Agent', 'Password Change Test Agent')
            ->patch(route('password.change.required.update'), $this->changePayload());

        $audit = AuditLog::query()->sole();
        $this->assertSame('account.initial_password_changed', $audit->action);
        $this->assertSame($user->id, $audit->actor_id);
        $this->assertSame($user->id, $audit->subject_id);
        $this->assertSame($user->getMorphClass(), $audit->subject_type);
        $this->assertSame([
            'must_change_password' => true,
            'password_changed_at' => null,
        ], $audit->old_values);
        $this->assertFalse($audit->new_values['must_change_password']);
        $this->assertNotNull($audit->new_values['password_changed_at']);
        $this->assertSame(['must_change_password', 'password_changed_at'], array_keys($audit->new_values));
        $this->assertSame('Password Change Test Agent', $audit->user_agent);

        $serializedAudit = json_encode([$audit->old_values, $audit->new_values]);
        $this->assertStringNotContainsString('password_hash', $serializedAudit);
        $this->assertStringNotContainsString('new-secure-password', $serializedAudit);
    }

    public function test_audit_failure_rolls_back_password_and_change_state(): void
    {
        $user = User::factory()->mustChangePassword()->create();
        $originalHash = $user->password;

        $this->mock(AuditLogger::class)
            ->shouldReceive('log')
            ->once()
            ->andThrow(new RuntimeException('Audit storage failed.'));

        $this->withoutExceptionHandling();

        try {
            $this->actingAs($user)
                ->patch(route('password.change.required.update'), $this->changePayload());
            $this->fail('The audit failure should escape the request.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit storage failed.', $exception->getMessage());
        }

        $user->refresh();
        $this->assertSame($originalHash, $user->password);
        $this->assertTrue($user->must_change_password);
        $this->assertNull($user->password_changed_at);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_user_without_pending_change_cannot_invoke_update(): void
    {
        $user = User::factory()->create();
        $originalHash = $user->password;

        $this->actingAs($user)
            ->patch(route('password.change.required.update'), $this->changePayload())
            ->assertSessionHasErrors('password');

        $user->refresh();
        $this->assertSame($originalHash, $user->password);
        $this->assertFalse($user->must_change_password);
        $this->assertNull($user->password_changed_at);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    /**
     * @return array<string, string>
     */
    private function credentials(User $user, string $password = 'password'): array
    {
        return ['email' => $user->email, 'password' => $password];
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function changePayload(array $overrides = []): array
    {
        return array_merge([
            'current_password' => 'password',
            'password' => 'new-secure-password',
            'password_confirmation' => 'new-secure-password',
        ], $overrides);
    }
}
