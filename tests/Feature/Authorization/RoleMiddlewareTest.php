<?php

namespace Tests\Feature\Authorization;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RoleMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('web')->group(function (): void {
            Route::get('/testing/admin-role', fn () => 'allowed')->middleware('role:admin');
            Route::get('/testing/super-admin-role', fn () => 'allowed')->middleware('role:super_admin');
            Route::get('/testing/administrator-roles', fn () => 'allowed')->middleware('role:admin,super_admin');
            Route::get('/testing/invalid-role', fn () => 'allowed')->middleware('role:admin,invalid');
            Route::get('/testing/missing-role-parameters', fn () => 'allowed')->middleware('role');
        });
    }

    public function test_normal_user_is_forbidden_from_admin_only_access(): void
    {
        $this->actingAs(User::factory()->user()->create())
            ->get('/testing/admin-role')
            ->assertForbidden();
    }

    public function test_admin_is_allowed_when_admin_is_explicitly_listed(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get('/testing/administrator-roles')
            ->assertOk();
    }

    public function test_super_admin_is_allowed_when_super_admin_is_explicitly_listed(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->get('/testing/administrator-roles')
            ->assertOk();
    }

    public function test_super_admin_is_forbidden_when_only_admin_is_listed(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->get('/testing/admin-role')
            ->assertForbidden();
    }

    public function test_admin_is_forbidden_from_super_admin_only_access(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get('/testing/super-admin-role')
            ->assertForbidden();
    }

    public function test_invalid_middleware_role_parameters_fail_closed(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get('/testing/invalid-role')
            ->assertForbidden();
    }

    public function test_missing_middleware_role_parameters_fail_closed(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->get('/testing/missing-role-parameters')
            ->assertForbidden();
    }
}
