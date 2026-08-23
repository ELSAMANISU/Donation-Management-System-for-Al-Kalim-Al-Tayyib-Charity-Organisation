<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class CategoryManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login_for_every_category_route(): void
    {
        $this->get(route('admin.categories.index'))->assertRedirect(route('login'));
        $this->get(route('admin.categories.create'))->assertRedirect(route('login'));
        $this->post(route('admin.categories.store'), $this->validPayload())->assertRedirect(route('login'));
    }

    public function test_normal_user_is_forbidden_for_every_category_route(): void
    {
        $user = User::factory()->user()->create();

        $this->actingAs($user)->get(route('admin.categories.index'))->assertForbidden();
        $this->get(route('admin.categories.create'))->assertForbidden();
        $this->post(route('admin.categories.store'), $this->validPayload())->assertForbidden();
    }

    public function test_admin_and_super_admin_may_view_and_create_categories(): void
    {
        foreach ([User::factory()->admin()->create(), User::factory()->superAdmin()->create()] as $actor) {
            $this->actingAs($actor)
                ->get(route('admin.categories.index'))
                ->assertOk()
                ->assertSee(route('admin.categories.create'));

            $this->get(route('admin.categories.create'))
                ->assertOk()
                ->assertSeeText('Create Category / إنشاء فئة');

            $this->post(route('admin.categories.store'), $this->validPayload([
                'slug' => 'category-'.$actor->id,
            ]))->assertRedirect(route('admin.categories.index'));
        }

        $this->assertDatabaseCount('categories', 2);
    }

    public function test_disabled_administrator_cannot_use_the_workflow(): void
    {
        $admin = User::factory()->admin()->disabled()->create();

        $this->actingAs($admin)
            ->get(route('admin.categories.index'))
            ->assertRedirect(route('login'));

        $this->get(route('admin.categories.create'))
            ->assertRedirect(route('login'));
        $this->post(route('admin.categories.store'), $this->validPayload())
            ->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertDatabaseCount('categories', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_administrator_pending_password_change_cannot_use_the_workflow(): void
    {
        $admin = User::factory()->admin()->mustChangePassword()->create();

        $this->actingAs($admin)
            ->get(route('admin.categories.index'))
            ->assertRedirect(route('password.change.required.edit'));
        $this->get(route('admin.categories.create'))
            ->assertRedirect(route('password.change.required.edit'));
        $this->post(route('admin.categories.store'), $this->validPayload())
            ->assertRedirect(route('password.change.required.edit'));

        $this->assertDatabaseCount('categories', 0);
    }

    public function test_successful_creation_trims_bilingual_content_and_normalizes_slug(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('admin.categories.store'), $this->validPayload([
                'name_ar' => '  الرعاية الصحية  ',
                'name_en' => '  Health Care  ',
                'slug' => '  Emergency HEALTH Care  ',
                'description_ar' => '  وصف عربي  ',
                'description_en' => '  English description  ',
            ]))
            ->assertRedirect(route('admin.categories.index'))
            ->assertSessionHas('status', 'category-created');

        $category = Category::query()->sole();
        $this->assertSame('الرعاية الصحية', $category->name_ar);
        $this->assertSame('Health Care', $category->name_en);
        $this->assertSame('emergency-health-care', $category->slug);
        $this->assertSame('وصف عربي', $category->description_ar);
        $this->assertSame('English description', $category->description_en);
        $this->assertTrue($category->is_active);
        $this->assertSame(0, $category->display_order);
        $this->assertSame($admin->id, $category->created_by);
        $this->assertSame($admin->id, $category->updated_by);
        $this->assertNull($category->icon);
        $this->assertNull($category->image_path);
    }

    public function test_slug_that_is_empty_after_normalization_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->from(route('admin.categories.create'))
            ->post(route('admin.categories.store'), $this->validPayload(['slug' => '--- !!! ---']))
            ->assertRedirect(route('admin.categories.create'))
            ->assertSessionHasErrors('slug');

        $this->assertDatabaseCount('categories', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_duplicate_normalized_slug_is_rejected_including_when_soft_deleted(): void
    {
        $admin = User::factory()->admin()->create();
        Category::factory()->trashed()->create(['slug' => 'food-support']);

        $this->actingAs($admin)
            ->from(route('admin.categories.create'))
            ->post(route('admin.categories.store'), $this->validPayload(['slug' => ' FOOD Support ']))
            ->assertRedirect(route('admin.categories.create'))
            ->assertSessionHasErrors('slug');

        $this->assertSame(1, Category::withTrashed()->count());
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_validation_failures_create_neither_category_nor_audit(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('admin.categories.store'), [
                'name_ar' => '',
                'name_en' => '',
                'slug' => '',
                'description_en' => str_repeat('x', 5001),
            ])
            ->assertSessionHasErrors(['name_ar', 'name_en', 'slug', 'description_en']);

        $this->assertDatabaseCount('categories', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_injected_administrative_fields_are_ignored(): void
    {
        $admin = User::factory()->admin()->create();
        $other = User::factory()->superAdmin()->create();

        $this->actingAs($admin)->post(route('admin.categories.store'), [
            ...$this->validPayload(),
            'id' => 999,
            'is_active' => false,
            'display_order' => 99,
            'created_by' => $other->id,
            'updated_by' => $other->id,
            'deleted_at' => now(),
            'icon' => 'injected-icon',
            'image_path' => 'injected/path.jpg',
        ])->assertRedirect(route('admin.categories.index'));

        $category = Category::query()->sole();
        $this->assertNotSame(999, $category->id);
        $this->assertTrue($category->is_active);
        $this->assertSame(0, $category->display_order);
        $this->assertSame($admin->id, $category->created_by);
        $this->assertSame($admin->id, $category->updated_by);
        $this->assertNull($category->deleted_at);
        $this->assertNull($category->icon);
        $this->assertNull($category->image_path);
    }

    public function test_creation_writes_only_approved_safe_audit_metadata(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('admin.categories.store'), $this->validPayload([
            'description_ar' => 'Private audit exclusion marker Arabic',
            'description_en' => 'Private audit exclusion marker English',
            'icon' => 'injected-icon',
        ]))->assertRedirect(route('admin.categories.index'));

        $category = Category::query()->sole();
        $audit = AuditLog::query()->sole();

        $this->assertSame('category.created', $audit->action);
        $this->assertSame($admin->id, $audit->actor_id);
        $this->assertSame($category->id, $audit->subject_id);
        $this->assertNull($audit->old_values);
        $this->assertSame([
            'name_ar' => 'الغذاء',
            'name_en' => 'Food Support',
            'slug' => 'food-support',
            'is_active' => true,
            'display_order' => 0,
        ], $audit->new_values);
        $encodedAudit = json_encode($audit->getAttributes());
        $this->assertStringNotContainsString('Private audit exclusion marker', $encodedAudit);
        $this->assertStringNotContainsString('injected-icon', $encodedAudit);
    }

    public function test_audit_failure_rolls_back_category_creation(): void
    {
        $admin = User::factory()->admin()->create();
        $this->mock(AuditLogger::class)
            ->shouldReceive('log')
            ->once()
            ->andThrow(new RuntimeException('Audit storage failed.'));
        $this->withoutExceptionHandling();

        try {
            $this->actingAs($admin)->post(route('admin.categories.store'), $this->validPayload());
            $this->fail('The audit failure should escape the request.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit storage failed.', $exception->getMessage());
        }

        $this->assertDatabaseCount('categories', 0);
    }

    public function test_concurrent_duplicate_slug_failure_returns_safe_validation_error(): void
    {
        $admin = User::factory()->admin()->create();
        DB::statement("CREATE TRIGGER concurrent_category_slug BEFORE INSERT ON categories WHEN NEW.slug = 'race-slug' BEGIN SELECT RAISE(ABORT, 'UNIQUE constraint failed: categories.slug'); END");

        $this->actingAs($admin)
            ->from(route('admin.categories.create'))
            ->post(route('admin.categories.store'), $this->validPayload(['slug' => 'race-slug']))
            ->assertRedirect(route('admin.categories.create'))
            ->assertSessionHasErrors(['slug' => 'This category slug is already in use.']);

        $this->assertDatabaseMissing('categories', ['slug' => 'race-slug']);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_index_includes_active_and_inactive_but_excludes_soft_deleted_categories(): void
    {
        $admin = User::factory()->admin()->create();
        $active = Category::factory()->create(['name_en' => 'Visible Active']);
        $inactive = Category::factory()->inactive()->create(['name_en' => 'Visible Inactive']);
        $trashed = Category::factory()->trashed()->create(['name_en' => 'Hidden Trashed']);

        $this->actingAs($admin)
            ->get(route('admin.categories.index'))
            ->assertOk()
            ->assertSee($active->name_en)
            ->assertSee($inactive->name_en)
            ->assertDontSee($trashed->name_en);
    }

    public function test_index_ordering_pagination_and_selected_fields_are_deterministic(): void
    {
        $admin = User::factory()->admin()->create();
        Category::factory()->atPosition(1)->create(['name_en' => 'Zulu']);
        $alphaFirst = Category::factory()->atPosition(1)->create(['name_en' => 'Alpha']);
        $alphaSecond = Category::factory()->atPosition(1)->create(['name_en' => 'Alpha']);
        Category::factory()->count(13)->atPosition(2)->create();

        $this->actingAs($admin)
            ->get(route('admin.categories.index'))
            ->assertOk()
            ->assertViewHas('categories', function ($categories) use ($alphaFirst, $alphaSecond): bool {
                $first = $categories->getCollection()->first();

                return $categories->perPage() === 15
                    && $categories->total() === 16
                    && $categories->count() === 15
                    && $categories->pluck('id')->take(2)->all() === [$alphaFirst->id, $alphaSecond->id]
                    && array_keys($first->getAttributes()) === [
                        'id', 'name_ar', 'name_en', 'slug', 'image_path', 'is_active', 'display_order', 'created_at',
                    ];
            })
            ->assertSee('page=2', false);
    }

    public function test_index_escapes_database_content_and_exposes_no_unselected_fields(): void
    {
        $admin = User::factory()->admin()->create();
        $unsafe = '<script>alert("category")</script>';
        Category::factory()->create([
            'name_ar' => $unsafe,
            'name_en' => $unsafe,
            'description_ar' => 'private-description-ar',
            'description_en' => 'private-description-en',
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.categories.index'))
            ->assertOk()
            ->assertSee($unsafe)
            ->assertDontSee($unsafe, false)
            ->assertDontSee('private-description-ar')
            ->assertDontSee('private-description-en');
    }

    public function test_navigation_visibility_matches_category_authorization(): void
    {
        $normal = User::factory()->user()->create();
        $admin = User::factory()->admin()->create();
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($normal)->get('/dashboard')
            ->assertDontSee(route('admin.categories.index'));
        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertSee(route('admin.categories.index'));
        $this->actingAs($superAdmin)->get(route('admin.dashboard'))
            ->assertSee(route('admin.categories.index'));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name_ar' => 'الغذاء',
            'name_en' => 'Food Support',
            'slug' => 'food-support',
            'description_ar' => 'دعم الغذاء للأسر المحتاجة.',
            'description_en' => 'Food support for families in need.',
        ], $overrides);
    }
}
