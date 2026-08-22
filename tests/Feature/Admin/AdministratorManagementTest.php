<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\TemporaryPasswordGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

class AdministratorManagementTest extends TestCase
{
    use RefreshDatabase;

    private const TEMPORARY_PASSWORD = 'Secure-Temporary-2048!';

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('admin.administrators.index'))->assertRedirect(route('login'));
        $this->get(route('admin.administrators.create'))->assertRedirect(route('login'));
        $this->post(route('admin.administrators.store'))->assertRedirect(route('login'));
    }

    public function test_normal_user_and_admin_are_forbidden(): void
    {
        foreach ([UserRole::User, UserRole::Admin] as $role) {
            $actor = User::factory()->create(['role' => $role]);

            $this->actingAs($actor)->get(route('admin.administrators.index'))->assertForbidden();
            $this->actingAs($actor)->get(route('admin.administrators.create'))->assertForbidden();
            $this->actingAs($actor)->post(route('admin.administrators.store'), $this->validPayload())->assertForbidden();
        }
    }

    public function test_super_admin_can_view_index_and_create_form(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->get(route('admin.administrators.index'))
            ->assertOk()
            ->assertSee('Administrators')
            ->assertSee(route('admin.administrators.create'));

        $this->get(route('admin.administrators.create'))
            ->assertOk()
            ->assertSee('The system will generate a secure temporary password')
            ->assertSeeText('Create Administrator / إنشاء مسؤول')
            ->assertSee('bg-indigo-600')
            ->assertDontSee('name="password"', false)
            ->assertDontSee('name="role"', false);
    }

    public function test_disabled_super_admin_is_logged_out(): void
    {
        $superAdmin = User::factory()->superAdmin()->disabled()->create();

        $this->actingAs($superAdmin)
            ->get(route('admin.administrators.index'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_flagged_super_admin_must_change_password_first(): void
    {
        $superAdmin = User::factory()->superAdmin()->mustChangePassword()->create();

        $this->actingAs($superAdmin)
            ->get(route('admin.administrators.index'))
            ->assertRedirect(route('password.change.required.edit'));
    }

    public function test_navigation_and_create_links_are_visible_only_to_super_admin(): void
    {
        $normal = User::factory()->user()->create();
        $admin = User::factory()->admin()->create();
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($normal)->get('/dashboard')
            ->assertDontSee(route('admin.administrators.index'));
        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertDontSee(route('admin.administrators.index'));
        $this->actingAs($superAdmin)->get(route('admin.dashboard'))
            ->assertSee(route('admin.administrators.index'));

        $this->get(route('admin.administrators.index'))
            ->assertSee(route('admin.administrators.create'));
    }

    public function test_index_lists_only_administrator_roles_with_fifteen_per_page(): void
    {
        $superAdmin = User::factory()->superAdmin()->create(['name' => 'Viewing Super Admin']);
        User::factory()->count(15)->admin()->create();
        User::factory()->user()->create(['name' => 'Excluded Normal User']);

        $this->actingAs($superAdmin)
            ->get(route('admin.administrators.index'))
            ->assertOk()
            ->assertViewHas('administrators', fn ($administrators): bool => $administrators->perPage() === 15
                && $administrators->total() === 16
                && $administrators->count() === 15)
            ->assertDontSee('Excluded Normal User')
            ->assertSee('page=2', false);
    }

    public function test_index_excludes_sensitive_fields_and_escapes_database_html(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $unsafeName = '<script>alert("administrator")</script>';
        $administrator = User::factory()->admin()->disabled()->create([
            'name' => $unsafeName,
            'password' => Hash::make('private-hash-source'),
            'remember_token' => 'private-remember-token',
            'disabled_reason' => 'private-disable-reason',
        ]);

        $this->actingAs($superAdmin)
            ->get(route('admin.administrators.index'))
            ->assertOk()
            ->assertSee($unsafeName)
            ->assertDontSee($unsafeName, false)
            ->assertDontSee($administrator->password)
            ->assertDontSee('private-remember-token')
            ->assertDontSee('private-disable-reason');
    }

    public function test_success_creates_one_active_admin_and_returns_password_once_with_private_headers(): void
    {
        $this->useDeterministicPassword();
        Log::spy();
        $superAdmin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($superAdmin)
            ->post(route('admin.administrators.store'), $this->validPayload([
                'name' => '  New Administrator  ',
                'email' => '  NEW.ADMIN@EXAMPLE.COM ',
            ]));

        $response->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Pragma', 'no-cache')
            ->assertSee(self::TEMPORARY_PASSWORD)
            ->assertSee('Administrator created successfully');

        $administrator = User::query()->where('email', 'new.admin@example.com')->firstOrFail();
        $this->assertSame('New Administrator', $administrator->name);
        $this->assertSame(UserRole::Admin, $administrator->role);
        $this->assertTrue($administrator->is_active);
        $this->assertTrue($administrator->must_change_password);
        $this->assertNull($administrator->password_changed_at);
        $this->assertNull($administrator->disabled_at);
        $this->assertNull($administrator->disabled_reason);
        $this->assertNull($administrator->disabled_by);
        $this->assertGreaterThanOrEqual(20, strlen(self::TEMPORARY_PASSWORD));
        $this->assertTrue(Hash::check(self::TEMPORARY_PASSWORD, $administrator->password));
        $this->assertDatabaseCount('users', 2);
        $this->assertStringNotContainsString(self::TEMPORARY_PASSWORD, $response->headers->get('Location', ''));
        $this->assertStringNotContainsString(self::TEMPORARY_PASSWORD, json_encode(session()->all()));

        $this->get(route('admin.administrators.index'))
            ->assertDontSee(self::TEMPORARY_PASSWORD);

        foreach (['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'] as $level) {
            Log::getFacadeRoot()->shouldNotHaveReceived(
                $level,
                fn (...$arguments): bool => str_contains(json_encode($arguments), self::TEMPORARY_PASSWORD),
            );
        }
    }

    public function test_client_cannot_inject_password_role_or_account_state(): void
    {
        $this->useDeterministicPassword();
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)->post(route('admin.administrators.store'), [
            ...$this->validPayload(),
            'password' => 'client-selected-password',
            'password_confirmation' => 'client-selected-password',
            'role' => UserRole::SuperAdmin->value,
            'is_active' => false,
            'must_change_password' => false,
            'password_changed_at' => now(),
            'disabled_reason' => 'Injected',
        ])->assertOk();

        $administrator = User::query()->where('email', 'new.admin@example.com')->firstOrFail();
        $this->assertSame(UserRole::Admin, $administrator->role);
        $this->assertTrue($administrator->is_active);
        $this->assertTrue($administrator->must_change_password);
        $this->assertNull($administrator->password_changed_at);
        $this->assertTrue(Hash::check(self::TEMPORARY_PASSWORD, $administrator->password));
        $this->assertFalse(Hash::check('client-selected-password', $administrator->password));
    }

    public function test_success_view_escapes_user_supplied_html(): void
    {
        $this->useDeterministicPassword();
        $superAdmin = User::factory()->superAdmin()->create();
        $unsafeName = '<svg onload=alert("created")>';

        $this->actingAs($superAdmin)
            ->post(route('admin.administrators.store'), $this->validPayload(['name' => $unsafeName]))
            ->assertOk()
            ->assertSee($unsafeName)
            ->assertDontSee($unsafeName, false);
    }

    public function test_duplicate_email_is_rejected_without_modifying_existing_account(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $existing = User::factory()->user()->create(['email' => 'existing@example.com']);
        $originalPassword = $existing->password;
        $originalName = $existing->name;

        $this->actingAs($superAdmin)
            ->from(route('admin.administrators.create'))
            ->post(route('admin.administrators.store'), $this->validPayload([
                'email' => ' EXISTING@EXAMPLE.COM ',
            ]))
            ->assertRedirect(route('admin.administrators.create'))
            ->assertSessionHasErrors('email');

        $existing->refresh();
        $this->assertSame($originalName, $existing->name);
        $this->assertSame($originalPassword, $existing->password);
        $this->assertSame(UserRole::User, $existing->role);
        $this->assertTrue($existing->is_active);
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_invalid_or_missing_fields_create_no_account_and_escape_old_html(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $unsafeName = '<img src=x onerror=alert("name")>';

        $response = $this->actingAs($superAdmin)
            ->from(route('admin.administrators.create'))
            ->post(route('admin.administrators.store'), [
                'name' => $unsafeName,
                'email' => 'not-an-email',
            ]);

        $response->assertRedirect(route('admin.administrators.create'))
            ->assertSessionHasErrors('email');
        $this->followingRedirects()->get(route('admin.administrators.create'))
            ->assertSee($unsafeName)
            ->assertDontSee($unsafeName, false);

        $this->post(route('admin.administrators.store'), [])
            ->assertSessionHasErrors(['name', 'email']);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_concurrent_duplicate_constraint_is_converted_to_safe_validation(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        DB::statement("CREATE TRIGGER concurrent_email_duplicate BEFORE INSERT ON users WHEN NEW.email = 'race@example.com' BEGIN SELECT RAISE(ABORT, 'UNIQUE constraint failed: users.email'); END");

        $this->actingAs($superAdmin)
            ->from(route('admin.administrators.create'))
            ->post(route('admin.administrators.store'), $this->validPayload(['email' => 'race@example.com']))
            ->assertRedirect(route('admin.administrators.create'))
            ->assertSessionHasErrors('email');

        $this->assertDatabaseMissing('users', ['email' => 'race@example.com']);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_audit_is_privacy_safe(): void
    {
        $this->useDeterministicPassword();
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->withHeader('User-Agent', 'Administrator Creation Test')
            ->post(route('admin.administrators.store'), $this->validPayload())
            ->assertOk();

        $administrator = User::query()->where('email', 'new.admin@example.com')->firstOrFail();
        $audit = AuditLog::query()->sole();
        $this->assertSame('administrator.created', $audit->action);
        $this->assertSame($superAdmin->id, $audit->actor_id);
        $this->assertSame($administrator->id, $audit->subject_id);
        $this->assertNull($audit->old_values);
        $this->assertSame([
            'role' => UserRole::Admin->value,
            'is_active' => true,
            'must_change_password' => true,
        ], $audit->new_values);
        $this->assertStringNotContainsString(self::TEMPORARY_PASSWORD, json_encode($audit->getAttributes()));
    }

    public function test_audit_failure_rolls_back_creation(): void
    {
        $rollbackActor = User::factory()->superAdmin()->create();

        $this->mock(AuditLogger::class)
            ->shouldReceive('log')
            ->once()
            ->andThrow(new RuntimeException('Audit storage failed.'));
        $this->withoutExceptionHandling();

        try {
            $this->actingAs($rollbackActor)->post(route('admin.administrators.store'), $this->validPayload([
                'email' => 'rollback@example.com',
            ]));
            $this->fail('The audit failure should escape the request.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit storage failed.', $exception->getMessage());
        }

        $this->assertDatabaseMissing('users', ['email' => 'rollback@example.com']);
    }

    public function test_created_admin_must_change_password_then_reaches_admin_dashboard(): void
    {
        $this->useDeterministicPassword();
        $superAdmin = User::factory()->superAdmin()->create();
        $this->actingAs($superAdmin)->post(route('admin.administrators.store'), $this->validPayload());
        $this->post(route('logout'));
        $administrator = User::query()->where('email', 'new.admin@example.com')->firstOrFail();

        $this->post('/login', [
            'email' => $administrator->email,
            'password' => self::TEMPORARY_PASSWORD,
        ])->assertRedirect(route('password.change.required.edit'));

        $this->patch(route('password.change.required.update'), [
            'current_password' => self::TEMPORARY_PASSWORD,
            'password' => 'new-private-password',
            'password_confirmation' => 'new-private-password',
        ])->assertRedirect(route('admin.dashboard'));
    }

    public function test_creation_is_rate_limited(): void
    {
        $this->useDeterministicPassword();
        $superAdmin = User::factory()->superAdmin()->create();

        foreach (range(1, 6) as $index) {
            $this->actingAs($superAdmin)
                ->post(route('admin.administrators.store'), $this->validPayload([
                    'email' => "administrator{$index}@example.com",
                ]))
                ->assertOk();
        }

        $this->post(route('admin.administrators.store'), $this->validPayload([
            'email' => 'rate-limited@example.com',
        ]))->assertTooManyRequests();
    }

    private function useDeterministicPassword(): void
    {
        $this->mock(TemporaryPasswordGenerator::class)
            ->shouldReceive('generate')
            ->andReturn(self::TEMPORARY_PASSWORD);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'New Administrator',
            'email' => 'new.admin@example.com',
        ], $overrides);
    }
}
