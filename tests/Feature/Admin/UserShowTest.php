<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $user = User::factory()->create();

        $this->get(route('admin.users.show', $user))->assertRedirect(route('login'));
    }

    public function test_normal_user_is_forbidden(): void
    {
        $actor = User::factory()->user()->create();
        $target = User::factory()->user()->create();

        $this->actingAs($actor)
            ->get(route('admin.users.show', $target))
            ->assertForbidden();
    }

    public function test_admin_can_view_a_normal_users_details(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->user()->create(['name' => 'Details Target']);

        $this->actingAs($admin)
            ->get(route('admin.users.show', $target))
            ->assertOk()
            ->assertSee('Details Target');
    }

    public function test_super_admin_can_view_user_details(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $target = User::factory()->admin()->create();

        $this->actingAs($superAdmin)
            ->get(route('admin.users.show', $target))
            ->assertOk()
            ->assertSee($target->email);
    }

    public function test_disabled_admin_is_logged_out_and_redirected(): void
    {
        $admin = User::factory()->admin()->disabled()->create();
        $target = User::factory()->user()->create();

        $response = $this->actingAs($admin)->get(route('admin.users.show', $target));

        $this->assertGuest();
        $response->assertRedirect(route('login'));
    }

    public function test_correct_account_fields_are_displayed(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->user()->create([
            'name' => 'Account Projection',
            'email' => 'projection@example.com',
            'created_at' => '2026-05-06 07:08:09',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.users.show', $target))
            ->assertOk()
            ->assertSee((string) $target->id)
            ->assertSee('Account Projection')
            ->assertSee('projection@example.com')
            ->assertSee(UserRole::User->value)
            ->assertSee('Active')
            ->assertSee('2026-05-06 07:08');
    }

    public function test_disabled_account_displays_disablement_information(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Disabling Administrator']);
        $target = User::factory()->disabled()->create([
            'disabled_at' => '2026-04-03 02:01:00',
            'disabled_reason' => 'Repeated policy violations',
            'disabled_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.users.show', $target))
            ->assertOk()
            ->assertSee('Disabled at')
            ->assertSee('2026-04-03 02:01')
            ->assertSee('Disabling Administrator')
            ->assertSee('Repeated policy violations');
    }

    public function test_active_account_does_not_display_stale_disablement_information(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->user()->create();
        $target->forceFill([
            'disabled_at' => '2026-01-01 00:00:00',
            'disabled_reason' => 'Stale reason must stay hidden',
            'disabled_by' => $admin->id,
        ])->save();

        $this->actingAs($admin)
            ->get(route('admin.users.show', $target))
            ->assertOk()
            ->assertDontSee('Disabled at')
            ->assertDontSee('Stale reason must stay hidden');
    }

    public function test_relevant_activity_is_displayed_newest_first_and_other_subjects_are_excluded(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->user()->create();
        $otherTarget = User::factory()->user()->create();

        $this->createActivity($target, 'user.disabled', 'First Actor', '2026-01-01 10:00:00', true, false);
        $this->createActivity($target, 'user.reactivated', 'Second Actor', '2026-01-02 10:00:00', false, true);
        $this->createActivity($otherTarget, 'user.disabled', 'Other Subject Actor', '2026-01-03 10:00:00', true, false);

        $this->actingAs($admin)
            ->get(route('admin.users.show', $target))
            ->assertOk()
            ->assertSeeInOrder(['Account reactivated', 'Account disabled'])
            ->assertSee('Disabled')
            ->assertSee('Active')
            ->assertDontSee('Other Subject Actor');
    }

    public function test_deleted_actor_still_displays_stored_name_and_role_snapshots(): void
    {
        $admin = User::factory()->admin()->create();
        $deletedActor = User::factory()->superAdmin()->create(['name' => 'Former Super Administrator']);
        $target = User::factory()->user()->create();

        AuditLog::factory()->create([
            'actor_id' => $deletedActor->id,
            'actor_name' => 'Former Super Administrator',
            'actor_role' => UserRole::SuperAdmin->value,
            'action' => 'user.disabled',
            'subject_type' => $target->getMorphClass(),
            'subject_id' => $target->id,
            'old_values' => ['is_active' => true],
            'new_values' => ['is_active' => false],
        ]);

        $deletedActor->delete();

        $this->actingAs($admin)
            ->get(route('admin.users.show', $target))
            ->assertOk()
            ->assertSee('Former Super Administrator')
            ->assertSee(UserRole::SuperAdmin->value);
    }

    public function test_empty_activity_history_shows_bilingual_empty_state(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->user()->create();

        $this->actingAs($admin)
            ->get(route('admin.users.show', $target))
            ->assertOk()
            ->assertSee('No activity recorded')
            ->assertSee('لا يوجد نشاط مسجل');
    }

    public function test_activity_pagination_uses_twenty_records_and_dedicated_page_name(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->user()->create();

        for ($index = 0; $index < 21; $index++) {
            $this->createActivity(
                $target,
                'user.disabled',
                'Pagination Actor '.$index,
                '2026-01-01 00:00:00',
                true,
                false,
            );
        }

        $this->actingAs($admin)
            ->get(route('admin.users.show', $target))
            ->assertOk()
            ->assertViewHas('activity', function ($activity): bool {
                return $activity->perPage() === 20
                    && $activity->count() === 20
                    && $activity->total() === 21
                    && $activity->getPageName() === 'activity_page';
            })
            ->assertSee('activity_page=2', false);
    }

    public function test_sensitive_account_and_audit_values_are_not_rendered(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->user()->create([
            'password' => Hash::make('private-password'),
            'remember_token' => 'private-remember-token',
        ]);

        AuditLog::factory()->create([
            'actor_name' => 'Safe Actor',
            'actor_role' => UserRole::Admin->value,
            'action' => 'user.disabled',
            'subject_type' => $target->getMorphClass(),
            'subject_id' => $target->id,
            'old_values' => ['is_active' => true, 'password' => 'audit-password-secret'],
            'new_values' => ['is_active' => false, 'token' => 'audit-token-secret'],
            'ip_address' => '198.51.100.44',
            'user_agent' => 'Private Audit User Agent',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.users.show', $target))
            ->assertOk()
            ->assertDontSee($target->password)
            ->assertDontSee('private-remember-token')
            ->assertDontSee('audit-password-secret')
            ->assertDontSee('audit-token-secret')
            ->assertDontSee('198.51.100.44')
            ->assertDontSee('Private Audit User Agent');
    }

    public function test_user_and_audit_supplied_html_is_escaped(): void
    {
        $admin = User::factory()->admin()->create();
        $unsafeUserName = '<script>alert("user")</script>';
        $unsafeActorName = '<img src=x onerror=alert("actor")>';
        $unsafeAction = '<svg onload=alert("action")>';
        $target = User::factory()->user()->create(['name' => $unsafeUserName]);

        AuditLog::factory()->create([
            'actor_name' => $unsafeActorName,
            'actor_role' => UserRole::Admin->value,
            'action' => $unsafeAction,
            'subject_type' => $target->getMorphClass(),
            'subject_id' => $target->id,
            'old_values' => ['is_active' => true],
            'new_values' => ['is_active' => false],
        ]);

        $this->actingAs($admin)
            ->get(route('admin.users.show', $target))
            ->assertOk()
            ->assertSee($unsafeUserName)
            ->assertSee($unsafeActorName)
            ->assertSee($unsafeAction)
            ->assertDontSee($unsafeUserName, false)
            ->assertDontSee($unsafeActorName, false)
            ->assertDontSee($unsafeAction, false);
    }

    public function test_index_detail_links_are_available_to_authorized_administrators(): void
    {
        $admin = User::factory()->admin()->create();
        $superAdmin = User::factory()->superAdmin()->create();
        $target = User::factory()->user()->create();

        $this->actingAs($admin)
            ->get(route('admin.users.index'))
            ->assertSee(route('admin.users.show', $target));
        $this->actingAs($superAdmin)
            ->get(route('admin.users.index'))
            ->assertSee(route('admin.users.show', $target));
        $this->actingAs($target)
            ->get(route('admin.users.index'))
            ->assertForbidden();
    }

    private function createActivity(
        User $subject,
        string $action,
        string $actorName,
        string $createdAt,
        bool $oldActive,
        bool $newActive,
    ): AuditLog {
        return AuditLog::factory()->create([
            'actor_name' => $actorName,
            'actor_role' => UserRole::Admin->value,
            'action' => $action,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->id,
            'old_values' => ['is_active' => $oldActive],
            'new_values' => ['is_active' => $newActive],
            'created_at' => $createdAt,
        ]);
    }
}
