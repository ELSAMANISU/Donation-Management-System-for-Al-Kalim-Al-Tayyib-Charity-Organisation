<?php

namespace Tests\Feature\Category;

use App\Models\Category;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CategoryDataFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_category_can_store_valid_bilingual_data_with_defaults_and_casts(): void
    {
        $category = Category::create([
            'name_ar' => 'الرعاية الصحية',
            'name_en' => 'Health Care',
            'slug' => '  HEALTH Care  ',
            'description_ar' => 'دعم الرعاية الصحية للأسر المحتاجة.',
            'description_en' => 'Health care support for families in need.',
            'icon' => 'heart-pulse',
            'image_path' => 'categories/health-care.webp',
        ])->fresh();

        $this->assertSame('الرعاية الصحية', $category->name_ar);
        $this->assertSame('دعم الرعاية الصحية للأسر المحتاجة.', $category->description_ar);
        $this->assertSame('health-care', $category->slug);
        $this->assertTrue($category->is_active);
        $this->assertSame(0, $category->display_order);
        $this->assertIsBool($category->is_active);
        $this->assertIsInt($category->display_order);
        $this->assertNull($category->deleted_at);
    }

    public function test_active_scope_excludes_inactive_categories(): void
    {
        $active = Category::factory()->create();
        Category::factory()->inactive()->create();

        $this->assertEquals([$active->id], Category::active()->pluck('id')->all());
    }

    public function test_soft_deleted_categories_are_excluded_retrievable_and_restorable(): void
    {
        $category = Category::factory()->create();
        $category->delete();

        $this->assertNull(Category::find($category->id));

        $trashed = Category::withTrashed()->findOrFail($category->id);
        $this->assertInstanceOf(Carbon::class, $trashed->deleted_at);

        $trashed->restore();

        $this->assertNotNull(Category::find($category->id));
    }

    public function test_display_order_scope_has_deterministic_secondary_ordering(): void
    {
        $zulu = Category::factory()->atPosition(1)->create(['name_en' => 'Zulu']);
        $alphaSecond = Category::factory()->atPosition(1)->create(['name_en' => 'Alpha']);
        $alphaFirst = Category::factory()->atPosition(1)->create(['name_en' => 'Alpha']);
        $later = Category::factory()->atPosition(2)->create(['name_en' => 'Earlier alphabetically']);

        $this->assertEquals(
            [$alphaSecond->id, $alphaFirst->id, $zulu->id, $later->id],
            Category::inDisplayOrder()->pluck('id')->all()
        );
    }

    public function test_creator_and_updater_relationships_work_and_null_on_user_deletion(): void
    {
        $creator = User::factory()->admin()->create();
        $updater = User::factory()->admin()->create();
        $category = Category::factory()->create([
            'created_by' => $creator->id,
            'updated_by' => $updater->id,
        ]);

        $this->assertTrue($category->creator->is($creator));
        $this->assertTrue($category->updater->is($updater));

        $creator->delete();
        $updater->delete();
        $category->refresh();

        $this->assertNull($category->created_by);
        $this->assertNull($category->updated_by);
        $this->assertDatabaseHas('categories', ['id' => $category->id]);
    }

    public function test_database_rejects_slugs_that_duplicate_after_normalization(): void
    {
        Category::factory()->create(['slug' => 'Emergency Food']);

        $this->expectException(QueryException::class);

        Category::factory()->create(['slug' => 'emergency-food']);
    }

    public function test_factory_inactive_order_and_trashed_states_are_deterministic(): void
    {
        $category = Category::factory()->inactive()->atPosition(17)->trashed()->create();

        $this->assertFalse($category->is_active);
        $this->assertSame(17, $category->display_order);
        $this->assertTrue($category->trashed());
    }

    public function test_administrative_fields_cannot_be_mass_assigned_by_ordinary_creation(): void
    {
        $actor = User::factory()->admin()->create();
        $category = Category::create([
            'name_ar' => 'الغذاء',
            'name_en' => 'Food',
            'slug' => 'food',
            'display_order' => 99,
            'is_active' => false,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
            'deleted_at' => now(),
        ])->fresh();

        $this->assertSame(0, $category->display_order);
        $this->assertTrue($category->is_active);
        $this->assertNull($category->created_by);
        $this->assertNull($category->updated_by);
        $this->assertNull($category->deleted_at);
    }

    public function test_category_data_is_not_exposed_by_any_route_or_the_static_homepage(): void
    {
        $category = Category::factory()->create([
            'name_en' => 'Foundation Only Private Marker',
            'name_ar' => 'علامة خاصة بطبقة البيانات',
        ]);

        $categoryRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_contains($route->uri(), 'categor'));

        $this->assertCount(3, $categoryRoutes);
        $this->assertTrue($categoryRoutes->every(
            fn ($route): bool => str_starts_with($route->uri(), 'admin/categories')
                && in_array('auth', $route->gatherMiddleware(), true)
                && in_array('role:admin,super_admin', $route->gatherMiddleware(), true)
        ));
        $this->get('/')
            ->assertOk()
            ->assertDontSee($category->name_en)
            ->assertDontSee($category->name_ar);
    }
}
