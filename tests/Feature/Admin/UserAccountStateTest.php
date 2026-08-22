<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\UserAccountStateService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class UserAccountStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_disable_or_reactivate_accounts(): void
    {
        $user = User::factory()->create();

        $this->patch(route('admin.users.disable', $user), ['disabled_reason' => 'Valid reason'])
            ->assertRedirect(route('login'));
        $this->patch(route('admin.users.reactivate', $user))
            ->assertRedirect(route('login'));
    }

    public function test_normal_user_cannot_disable_or_reactivate_accounts(): void
    {
        $actor = User::factory()->user()->create();
        $target = User::factory()->user()->create();

        $this->actingAs($actor)
            ->patch(route('admin.users.disable', $target), ['disabled_reason' => 'Valid reason'])
            ->assertForbidden();
        $this->actingAs($actor)
            ->patch(route('admin.users.reactivate', $target))
            ->assertForbidden();

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_admin_can_disable_and_reactivate_a_normal_user(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->user()->create();

        $this->actingAs($admin)
            ->patch(route('admin.users.disable', $user), ['disabled_reason' => '  Policy violation  '])
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHas('success');

        $user->refresh();
        $this->assertFalse($user->is_active);
        $this->assertSame('Policy violation', $user->disabled_reason);
        $this->assertSame($admin->id, $user->disabled_by);
        $this->assertNotNull($user->disabled_at);

        $this->actingAs($admin)
            ->patch(route('admin.users.reactivate', $user))
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHas('success');

        $user->refresh();
        $this->assertTrue($user->is_active);
        $this->assertNull($user->disabled_at);
        $this->assertNull($user->disabled_reason);
        $this->assertNull($user->disabled_by);
    }

    public function test_admin_cannot_change_administrator_account_state(): void
    {
        $actor = User::factory()->admin()->create();
        $admin = User::factory()->admin()->create();
        $superAdmin = User::factory()->superAdmin()->create();

        foreach ([$admin, $superAdmin] as $target) {
            $this->actingAs($actor)
                ->patch(route('admin.users.disable', $target), ['disabled_reason' => 'Valid reason'])
                ->assertForbidden();
            $this->actingAs($actor)
                ->patch(route('admin.users.reactivate', $target))
                ->assertForbidden();
        }

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_super_admin_can_change_normal_user_and_admin_state(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $user = User::factory()->user()->create();
        $admin = User::factory()->admin()->create();

        foreach ([$user, $admin] as $target) {
            $this->actingAs($actor)
                ->patch(route('admin.users.disable', $target), ['disabled_reason' => 'Approved disablement'])
                ->assertRedirect(route('admin.users.index'));
            $this->assertFalse($target->fresh()->is_active);

            $this->actingAs($actor)
                ->patch(route('admin.users.reactivate', $target))
                ->assertRedirect(route('admin.users.index'));
            $this->assertTrue($target->fresh()->is_active);
        }
    }

    public function test_service_reauthorizes_against_the_locked_current_role(): void
    {
        $actor = User::factory()->admin()->create();
        $staleTarget = User::factory()->user()->create();
        DB::table('users')->where('id', $staleTarget->id)->update([
            'role' => UserRole::Admin->value,
        ]);

        $this->expectException(AuthorizationException::class);

        try {
            app(UserAccountStateService::class)->disable(
                $actor,
                $staleTarget,
                'Stale authorization attempt',
                Request::create('/admin/users', 'PATCH'),
            );
        } finally {
            $this->assertTrue($staleTarget->fresh()->is_active);
            $this->assertDatabaseCount('audit_logs', 0);
        }
    }

    public function test_self_disablement_and_self_reactivation_are_rejected(): void
    {
        $actor = User::factory()->superAdmin()->create();
        User::factory()->superAdmin()->create();

        $this->actingAs($actor)
            ->from(route('admin.users.index'))
            ->patch(route('admin.users.disable', $actor), ['disabled_reason' => 'Self change'])
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHasErrors('user');

        $this->actingAs($actor)
            ->from(route('admin.users.index'))
            ->patch(route('admin.users.reactivate', $actor))
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHasErrors('user');

        $this->assertTrue($actor->fresh()->is_active);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_last_active_super_admin_cannot_be_disabled(): void
    {
        $actor = User::factory()->superAdmin()->create();

        $this->actingAs($actor)
            ->from(route('admin.users.index'))
            ->patch(route('admin.users.disable', $actor), ['disabled_reason' => 'Unsafe request'])
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHasErrors('user');

        $this->assertTrue($actor->fresh()->is_active);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_super_admin_can_disable_another_super_admin_when_one_remains_active(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $target = User::factory()->superAdmin()->create();

        $this->actingAs($actor)
            ->patch(route('admin.users.disable', $target), ['disabled_reason' => 'Administrator departure'])
            ->assertRedirect(route('admin.users.index'));

        $this->assertTrue($actor->fresh()->is_active);
        $this->assertFalse($target->fresh()->is_active);
    }

    public function test_disable_reason_validation_is_trimmed_and_enforces_length_limits(): void
    {
        $admin = User::factory()->admin()->create();

        foreach ([null, 'abcd', str_repeat('a', 1001)] as $reason) {
            $target = User::factory()->user()->create();

            $this->actingAs($admin)
                ->from(route('admin.users.index'))
                ->patch(route('admin.users.disable', $target), ['disabled_reason' => $reason])
                ->assertRedirect(route('admin.users.index'))
                ->assertSessionHasErrors('disabled_reason');

            $this->assertTrue($target->fresh()->is_active);
        }

        $target = User::factory()->user()->create();
        $this->actingAs($admin)->patch(route('admin.users.disable', $target), [
            'disabled_reason' => '  five+  ',
        ]);

        $this->assertSame('five+', $target->fresh()->disabled_reason);
    }

    public function test_injected_privileged_fields_and_role_are_ignored(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->user()->create();

        $this->actingAs($admin)->patch(route('admin.users.disable', $user), [
            'disabled_reason' => 'Legitimate reason',
            'is_active' => true,
            'disabled_at' => null,
            'disabled_by' => 999999,
            'role' => UserRole::SuperAdmin->value,
        ]);

        $user->refresh();
        $this->assertFalse($user->is_active);
        $this->assertSame(UserRole::User, $user->role);
        $this->assertSame($admin->id, $user->disabled_by);

        $this->actingAs($admin)->patch(route('admin.users.reactivate', $user), [
            'is_active' => false,
            'disabled_at' => now(),
            'disabled_by' => 999999,
            'role' => UserRole::Admin->value,
        ]);

        $user->refresh();
        $this->assertTrue($user->is_active);
        $this->assertSame(UserRole::User, $user->role);
        $this->assertNull($user->disabled_by);
    }

    public function test_repeated_lifecycle_actions_are_rejected_without_duplicate_audits(): void
    {
        $admin = User::factory()->admin()->create();
        $disabled = User::factory()->disabled()->create();
        $active = User::factory()->user()->create();

        $this->actingAs($admin)
            ->from(route('admin.users.index'))
            ->patch(route('admin.users.disable', $disabled), ['disabled_reason' => 'Duplicate attempt'])
            ->assertSessionHasErrors('user');
        $this->actingAs($admin)
            ->from(route('admin.users.index'))
            ->patch(route('admin.users.reactivate', $active))
            ->assertSessionHasErrors('user');

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_disabling_removes_only_target_database_sessions(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->user()->create();
        $other = User::factory()->user()->create();
        $this->insertSession('target-session', $target);
        $this->insertSession('other-session', $other);

        $this->actingAs($admin)->patch(route('admin.users.disable', $target), [
            'disabled_reason' => 'Security response',
        ]);

        $this->assertDatabaseMissing('sessions', ['id' => 'target-session']);
        $this->assertDatabaseHas('sessions', ['id' => 'other-session', 'user_id' => $other->id]);
    }

    public function test_disabled_account_cannot_login_and_reactivated_account_can_login_again(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->user()->create(['email' => 'state-login@example.com']);
        $service = app(UserAccountStateService::class);
        $request = Request::create('/admin/users', 'PATCH');

        $service->disable($admin, $user, 'Security response', $request);

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->assertGuest();

        $service->reactivate($admin, $user, $request);

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->assertAuthenticatedAs($user);
    }

    public function test_disable_and_reactivate_audits_contain_only_relevant_state_and_request_context(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->user()->create();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.25'])
            ->withHeader('User-Agent', 'Administration Browser/1.0')
            ->actingAs($admin)
            ->patch(route('admin.users.disable', $user), [
                'disabled_reason' => 'Security review',
                'password' => 'must-not-appear',
                'token' => 'must-not-appear',
            ]);

        $disabledAudit = AuditLog::query()->where('action', 'user.disabled')->firstOrFail();
        $this->assertSame($admin->id, $disabledAudit->actor_id);
        $this->assertSame($user->id, $disabledAudit->subject_id);
        $this->assertSame(['is_active' => true, 'disabled_at' => null, 'disabled_reason' => null, 'disabled_by' => null], $disabledAudit->old_values);
        $this->assertFalse($disabledAudit->new_values['is_active']);
        $this->assertSame('Security review', $disabledAudit->new_values['disabled_reason']);
        $this->assertSame($admin->id, $disabledAudit->new_values['disabled_by']);
        $this->assertSame('203.0.113.25', $disabledAudit->ip_address);
        $this->assertSame('Administration Browser/1.0', $disabledAudit->user_agent);
        $this->assertStringNotContainsString('must-not-appear', json_encode([$disabledAudit->old_values, $disabledAudit->new_values]));

        $this->actingAs($admin)->patch(route('admin.users.reactivate', $user));

        $reactivatedAudit = AuditLog::query()->where('action', 'user.reactivated')->firstOrFail();
        $this->assertFalse($reactivatedAudit->old_values['is_active']);
        $this->assertTrue($reactivatedAudit->new_values['is_active']);
        $this->assertNull($reactivatedAudit->new_values['disabled_at']);
        $this->assertNull($reactivatedAudit->new_values['disabled_reason']);
        $this->assertNull($reactivatedAudit->new_values['disabled_by']);
    }

    public function test_audit_failure_rolls_back_state_and_session_deletion(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->user()->create();
        $this->insertSession('rollback-session', $target);

        $this->mock(AuditLogger::class)
            ->shouldReceive('log')
            ->once()
            ->andThrow(new RuntimeException('Audit storage failed.'));

        $this->withoutExceptionHandling();

        try {
            $this->actingAs($admin)->patch(route('admin.users.disable', $target), [
                'disabled_reason' => 'Rollback required',
            ]);
            $this->fail('The audit failure should escape the request.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit storage failed.', $exception->getMessage());
        }

        $target->refresh();
        $this->assertTrue($target->is_active);
        $this->assertNull($target->disabled_at);
        $this->assertNull($target->disabled_reason);
        $this->assertNull($target->disabled_by);
        $this->assertDatabaseHas('sessions', ['id' => 'rollback-session', 'user_id' => $target->id]);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_sensitive_action_routes_are_rate_limited(): void
    {
        $admin = User::factory()->admin()->create();
        $targets = User::factory()->count(11)->user()->create();

        foreach ($targets->take(10) as $target) {
            $this->actingAs($admin)->patch(route('admin.users.disable', $target), [
                'disabled_reason' => 'Rate limit test',
            ])->assertRedirect(route('admin.users.index'));
        }

        $this->actingAs($admin)->patch(route('admin.users.disable', $targets->last()), [
            'disabled_reason' => 'Rate limit test',
        ])->assertTooManyRequests();
    }

    public function test_index_controls_follow_policy_state_and_hide_self_actions(): void
    {
        $admin = User::factory()->admin()->create();
        $activeUser = User::factory()->user()->create();
        $disabledUser = User::factory()->disabled()->create();
        $otherAdmin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get(route('admin.users.index'));

        $response->assertSee(route('admin.users.disable', $activeUser));
        $response->assertSee(route('admin.users.reactivate', $disabledUser));
        $response->assertDontSee(route('admin.users.disable', $admin));
        $response->assertDontSee(route('admin.users.reactivate', $admin));
        $response->assertDontSee(route('admin.users.disable', $otherAdmin));
    }

    private function insertSession(string $id, User $user): void
    {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Test Session',
            'payload' => 'test-payload',
            'last_activity' => now()->timestamp,
        ]);
    }
}
