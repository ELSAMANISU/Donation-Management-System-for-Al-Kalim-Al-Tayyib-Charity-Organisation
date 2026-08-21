<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class UserFactoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_role_states_produce_canonical_role_casts(): void
    {
        $user = User::factory()->user()->create();
        $admin = User::factory()->admin()->create();
        $superAdmin = User::factory()->superAdmin()->create();

        $this->assertSame(UserRole::User, $user->role);
        $this->assertSame(UserRole::Admin, $admin->role);
        $this->assertSame(UserRole::SuperAdmin, $superAdmin->role);
        $this->assertTrue($user->is_active);
        $this->assertTrue($admin->is_active);
        $this->assertTrue($superAdmin->is_active);
    }

    public function test_disabled_factory_state_produces_expected_values_and_casts(): void
    {
        $user = User::factory()->disabled()->create();

        $this->assertSame(UserRole::User, $user->role);
        $this->assertFalse($user->is_active);
        $this->assertInstanceOf(Carbon::class, $user->disabled_at);
        $this->assertSame('Disabled by factory state.', $user->disabled_reason);
        $this->assertNull($user->disabled_by);
    }
}
