<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/admin')->assertRedirect(route('login'));
    }

    public function test_normal_user_is_forbidden_from_accessing_administration_dashboard_directly(): void
    {
        $this->actingAs(User::factory()->user()->create())
            ->get('/admin')
            ->assertForbidden();
    }

    public function test_admin_can_access_administration_dashboard(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Dashboard Admin']);

        $this->actingAs($admin)
            ->get('/admin')
            ->assertOk()
            ->assertSee('Administration Dashboard')
            ->assertSee('لوحة الإدارة')
            ->assertSee('Dashboard Admin')
            ->assertSee('admin');
    }

    public function test_super_admin_can_access_administration_dashboard(): void
    {
        $superAdmin = User::factory()->superAdmin()->create(['name' => 'Dashboard Super Admin']);

        $this->actingAs($superAdmin)
            ->get('/admin')
            ->assertOk()
            ->assertSee('Dashboard Super Admin')
            ->assertSee('super_admin');
    }

    public function test_disabled_authenticated_administrator_is_logged_out_and_redirected_to_login(): void
    {
        $admin = User::factory()->admin()->create();
        $admin->forceFill(['is_active' => false])->save();

        $response = $this->actingAs($admin)->get('/admin');

        $this->assertGuest();
        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors([
            'email' => trans('auth.failed'),
        ]);
    }

    public function test_dashboard_displays_database_backed_counts_without_exposing_other_user_data(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Count Administrator']);
        User::factory()->user()->create(['email' => 'private-user@example.com']);
        User::factory()->user()->disabled()->create();
        User::factory()->superAdmin()->create();

        $this->actingAs($admin)
            ->get('/admin')
            ->assertOk()
            ->assertViewHas('counts', [
                'total_users' => 4,
                'active_users' => 3,
                'disabled_users' => 1,
                'administrator_accounts' => 2,
            ])
            ->assertSee('Total users')
            ->assertSee('Active users')
            ->assertSee('Disabled users')
            ->assertSee('Administrator accounts')
            ->assertDontSee('private-user@example.com');
    }

    public function test_authenticated_navigation_shows_admin_link_only_to_administrators(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->user()->create();

        $this->actingAs($admin)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Administration Dashboard')
            ->assertSee('لوحة الإدارة');

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertDontSee('Administration Dashboard');
    }
}
