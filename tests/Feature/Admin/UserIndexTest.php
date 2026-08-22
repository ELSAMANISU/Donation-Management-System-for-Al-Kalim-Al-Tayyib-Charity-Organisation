<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/admin/users')->assertRedirect(route('login'));
    }

    public function test_normal_user_is_forbidden(): void
    {
        $this->actingAs(User::factory()->user()->create())
            ->get('/admin/users')
            ->assertForbidden();
    }

    public function test_admin_can_view_the_user_list(): void
    {
        $admin = User::factory()->admin()->create();
        $listedUser = User::factory()->user()->create(['name' => 'Listed User']);

        $this->actingAs($admin)
            ->get('/admin/users')
            ->assertOk()
            ->assertSee('Listed User')
            ->assertSee($listedUser->email)
            ->assertSee('Name')
            ->assertSee('الاسم');
    }

    public function test_super_admin_can_view_the_user_list(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->get('/admin/users')
            ->assertOk()
            ->assertSee($superAdmin->email);
    }

    public function test_disabled_admin_is_logged_out_and_redirected_to_login(): void
    {
        $admin = User::factory()->admin()->create();
        $admin->forceFill(['is_active' => false])->save();

        $response = $this->actingAs($admin)->get('/admin/users');

        $this->assertGuest();
        $response->assertRedirect(route('login'));
    }

    public function test_name_search_returns_matches_and_excludes_nonmatches(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->create(['name' => 'Amina Search Match']);
        User::factory()->create(['name' => 'Unrelated Person']);

        $this->actingAs($admin)
            ->get('/admin/users?search=Amina')
            ->assertOk()
            ->assertSee('Amina Search Match')
            ->assertDontSee('Unrelated Person');
    }

    public function test_email_search_returns_matching_user(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->create(['email' => 'find-me@example.com']);
        User::factory()->create(['email' => 'exclude-me@example.com']);

        $this->actingAs($admin)
            ->get('/admin/users?search=find-me%40example.com')
            ->assertOk()
            ->assertSee('find-me@example.com')
            ->assertDontSee('exclude-me@example.com');
    }

    public function test_zero_search_value_applies_the_search_filter(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->create(['name' => 'User 0 Match', 'email' => 'zero-match@example.com']);
        User::factory()->create(['name' => 'Plain Person', 'email' => 'plain@example.com']);

        $this->actingAs($admin)
            ->get('/admin/users?search=0')
            ->assertOk()
            ->assertSee('User 0 Match')
            ->assertDontSee('Plain Person')
            ->assertDontSee('plain@example.com');
    }

    public function test_percent_and_underscore_are_literal_search_characters(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->create(['name' => 'Literal % User', 'email' => 'percent@example.com']);
        User::factory()->create(['name' => 'Ordinary User', 'email' => 'literal_name@example.com']);
        User::factory()->create(['name' => 'Excluded User', 'email' => 'literalxname@example.com']);

        $this->actingAs($admin)
            ->get('/admin/users?search=%25')
            ->assertOk()
            ->assertSee('percent@example.com')
            ->assertDontSee('literal_name@example.com')
            ->assertDontSee('literalxname@example.com');

        $this->actingAs($admin)
            ->get('/admin/users?search=_')
            ->assertOk()
            ->assertSee('literal_name@example.com')
            ->assertDontSee('percent@example.com')
            ->assertDontSee('literalxname@example.com');
    }

    public function test_search_longer_than_one_hundred_characters_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->from('/admin/users')
            ->get('/admin/users?search='.str_repeat('a', 101))
            ->assertRedirect('/admin/users')
            ->assertSessionHasErrors('search');
    }

    public function test_pagination_uses_fifteen_users_and_preserves_search_query(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->count(16)->sequence(
            fn ($sequence) => ['name' => 'Searchable User '.str_pad((string) $sequence->index, 2, '0', STR_PAD_LEFT)],
        )->create();

        $response = $this->actingAs($admin)->get('/admin/users?search=Searchable%20User');

        $response
            ->assertOk()
            ->assertViewHas('users', function ($users): bool {
                return $users->perPage() === 15
                    && $users->count() === 15
                    && $users->total() === 16;
            })
            ->assertSee('search=Searchable%20User', false);
    }

    public function test_empty_results_show_bilingual_empty_state(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get('/admin/users?search=no-user-matches-this')
            ->assertOk()
            ->assertSee('No users found')
            ->assertSee('لم يتم العثور على مستخدمين');
    }

    public function test_sensitive_fields_are_not_rendered(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->disabled()->create([
            'password' => Hash::make('private-password-value'),
            'remember_token' => 'private-remember-token',
            'disabled_reason' => 'private-disabled-reason',
            'disabled_at' => '2026-01-02 03:04:05',
        ]);

        $this->actingAs($admin)
            ->get('/admin/users')
            ->assertOk()
            ->assertDontSee($user->password)
            ->assertDontSee('private-remember-token')
            ->assertDontSee('private-disabled-reason')
            ->assertDontSee('2026-01-02 03:04:05');
    }

    public function test_user_supplied_html_is_escaped(): void
    {
        $admin = User::factory()->admin()->create();
        $unsafeName = '<script>alert("user-xss")</script>';
        User::factory()->create(['name' => $unsafeName]);

        $this->actingAs($admin)
            ->get('/admin/users')
            ->assertOk()
            ->assertSee($unsafeName)
            ->assertDontSee($unsafeName, false);
    }

    public function test_users_navigation_link_is_visible_only_to_authorized_administrators(): void
    {
        $admin = User::factory()->admin()->create();
        $superAdmin = User::factory()->superAdmin()->create();
        $user = User::factory()->user()->create();

        $this->actingAs($admin)->get('/dashboard')->assertSee(route('admin.users.index'));
        $this->actingAs($superAdmin)->get('/dashboard')->assertSee(route('admin.users.index'));
        $this->actingAs($user)->get('/dashboard')->assertDontSee(route('admin.users.index'));
    }
}
