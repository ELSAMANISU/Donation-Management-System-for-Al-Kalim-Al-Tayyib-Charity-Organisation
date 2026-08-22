<?php

namespace Tests\Feature\Authorization;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class UserPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_normal_user_has_no_user_management_abilities(): void
    {
        $actor = User::factory()->user()->create();
        $target = User::factory()->user()->create();

        $this->assertFalse(Gate::forUser($actor)->allows('viewAny', User::class));
        $this->assertFalse(Gate::forUser($actor)->allows('view', $target));
        $this->assertFalse(Gate::forUser($actor)->allows('changeAccountState', $target));
        $this->assertFalse(Gate::forUser($actor)->allows('createAdministrator', User::class));
        $this->assertFalse(Gate::forUser($actor)->allows('changeRole', $target));
    }

    public function test_admin_can_access_user_management_and_view_users(): void
    {
        $actor = User::factory()->admin()->create();
        $target = User::factory()->user()->create();

        $this->assertTrue(Gate::forUser($actor)->allows('viewAny', User::class));
        $this->assertTrue(Gate::forUser($actor)->allows('view', $target));
    }

    public function test_admin_can_change_account_state_for_normal_user_only(): void
    {
        $actor = User::factory()->admin()->create();
        $user = User::factory()->user()->create();
        $admin = User::factory()->admin()->create();
        $superAdmin = User::factory()->superAdmin()->create();

        $this->assertTrue(Gate::forUser($actor)->allows('changeAccountState', $user));
        $this->assertFalse(Gate::forUser($actor)->allows('changeAccountState', $admin));
        $this->assertFalse(Gate::forUser($actor)->allows('changeAccountState', $superAdmin));
    }

    public function test_admin_cannot_manage_administrators_create_administrators_or_change_roles(): void
    {
        $actor = User::factory()->admin()->create();
        $admin = User::factory()->admin()->create();
        $superAdmin = User::factory()->superAdmin()->create();

        $this->assertFalse(Gate::forUser($actor)->allows('manageAdministrator', $admin));
        $this->assertFalse(Gate::forUser($actor)->allows('manageAdministrator', $superAdmin));
        $this->assertFalse(Gate::forUser($actor)->allows('createAdministrator', User::class));
        $this->assertFalse(Gate::forUser($actor)->allows('changeRole', $admin));
        $this->assertFalse(Gate::forUser($actor)->allows('changeRole', $superAdmin));
    }

    public function test_super_admin_has_all_approved_user_management_abilities(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $user = User::factory()->user()->create();
        $admin = User::factory()->admin()->create();
        $superAdmin = User::factory()->superAdmin()->create();

        $this->assertTrue($actor->hasRole(UserRole::SuperAdmin));
        $this->assertTrue($actor->hasAnyRole([UserRole::Admin, UserRole::SuperAdmin]));
        $this->assertTrue(Gate::forUser($actor)->allows('viewAny', User::class));
        $this->assertTrue(Gate::forUser($actor)->allows('view', $user));
        $this->assertTrue(Gate::forUser($actor)->allows('changeAccountState', $user));
        $this->assertTrue(Gate::forUser($actor)->allows('changeAccountState', $admin));
        $this->assertTrue(Gate::forUser($actor)->allows('createAdministrator', User::class));
        $this->assertTrue(Gate::forUser($actor)->allows('manageAdministrator', $admin));
        $this->assertTrue(Gate::forUser($actor)->allows('manageAdministrator', $superAdmin));
        $this->assertTrue(Gate::forUser($actor)->allows('changeRole', $user));
        $this->assertTrue(Gate::forUser($actor)->allows('changeRole', $admin));
    }
}
