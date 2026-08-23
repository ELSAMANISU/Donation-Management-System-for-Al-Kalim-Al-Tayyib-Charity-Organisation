<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CategoryUpdateService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class CategoryUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_for_edit_and_update(): void
    {
        $category = Category::factory()->create();

        $this->get(route('admin.categories.edit', $category))->assertRedirect(route('login'));
        $this->patch(route('admin.categories.update', $category), $this->payload($category))->assertRedirect(route('login'));
    }

    public function test_normal_user_is_forbidden_for_edit_and_update(): void
    {
        $user = User::factory()->user()->create();
        $category = Category::factory()->create();

        $this->actingAs($user)->get(route('admin.categories.edit', $category))->assertForbidden();
        $this->patch(route('admin.categories.update', $category), $this->payload($category))->assertForbidden();
    }

    public function test_admin_and_super_admin_can_edit_and_update(): void
    {
        foreach ([UserRole::Admin, UserRole::SuperAdmin] as $role) {
            $actor = User::factory()->create(['role' => $role]);
            $category = Category::factory()->create(['slug' => 'original-'.$role->value]);

            $this->actingAs($actor)
                ->get(route('admin.categories.edit', $category))
                ->assertOk()
                ->assertSeeText('Edit Category / تعديل الفئة');

            $this->patch(route('admin.categories.update', $category), $this->payload($category, [
                'name_en' => 'Updated by '.$role->value,
            ]))->assertRedirect(route('admin.categories.index'));

            $this->assertSame('Updated by '.$role->value, $category->fresh()->name_en);
        }
    }

    public function test_disabled_administrator_cannot_edit_or_update(): void
    {
        $admin = User::factory()->admin()->disabled()->create();
        $category = Category::factory()->create();

        $this->actingAs($admin)
            ->get(route('admin.categories.edit', $category))
            ->assertRedirect(route('login'));
        $this->patch(route('admin.categories.update', $category), $this->payload($category))
            ->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_required_password_change_blocks_edit_and_update(): void
    {
        $admin = User::factory()->admin()->mustChangePassword()->create();
        $category = Category::factory()->create();

        $this->actingAs($admin)->get(route('admin.categories.edit', $category))
            ->assertRedirect(route('password.change.required.edit'));
        $this->patch(route('admin.categories.update', $category), $this->payload($category))
            ->assertRedirect(route('password.change.required.edit'));

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_soft_deleted_category_returns_not_found_for_edit_and_update(): void
    {
        $admin = User::factory()->admin()->create();
        $category = Category::factory()->trashed()->create();

        $this->actingAs($admin)->get(route('admin.categories.edit', $category->id))->assertNotFound();
        $this->patch(route('admin.categories.update', $category->id), $this->payload($category))->assertNotFound();
    }

    public function test_edit_form_prepopulates_and_escapes_existing_values(): void
    {
        $admin = User::factory()->admin()->create();
        $unsafe = '<script>alert("category-edit")</script>';
        $category = Category::factory()->atPosition(17)->create([
            'name_ar' => $unsafe,
            'name_en' => $unsafe,
            'description_ar' => $unsafe,
            'description_en' => $unsafe,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.categories.edit', $category))
            ->assertOk()
            ->assertSee($unsafe)
            ->assertDontSee($unsafe, false)
            ->assertSee('value="17"', false)
            ->assertSee('name="is_active"', false)
            ->assertDontSee('name="icon"', false)
            ->assertDontSee('name="image_path"', false);
    }

    public function test_successful_update_trims_content_normalizes_slug_and_sets_actor(): void
    {
        $creator = User::factory()->admin()->create();
        $updater = User::factory()->admin()->create();
        $category = Category::factory()->create([
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
        ]);

        $this->actingAs($updater)->patch(route('admin.categories.update', $category), $this->payload($category, [
            'name_ar' => '  الصحة  ',
            'name_en' => '  Health Care  ',
            'slug' => '  Emergency HEALTH  ',
            'description_ar' => '  وصف عربي محدث  ',
            'description_en' => '  Updated English description  ',
            'display_order' => 42,
            'is_active' => '0',
        ]))->assertRedirect(route('admin.categories.index'))
            ->assertSessionHas('status', 'category-updated');

        $category->refresh();
        $this->assertSame('الصحة', $category->name_ar);
        $this->assertSame('Health Care', $category->name_en);
        $this->assertSame('emergency-health', $category->slug);
        $this->assertSame('وصف عربي محدث', $category->description_ar);
        $this->assertSame('Updated English description', $category->description_en);
        $this->assertSame(42, $category->display_order);
        $this->assertFalse($category->is_active);
        $this->assertSame($creator->id, $category->created_by);
        $this->assertSame($updater->id, $category->updated_by);
    }

    public function test_slug_validation_rejects_empty_and_duplicate_normalized_values(): void
    {
        $admin = User::factory()->admin()->create();
        $category = Category::factory()->create(['slug' => 'current-slug']);
        Category::factory()->create(['slug' => 'taken-slug']);

        foreach (['--- !!! ---', ' TAKEN Slug '] as $slug) {
            $this->actingAs($admin)
                ->from(route('admin.categories.edit', $category))
                ->patch(route('admin.categories.update', $category), $this->payload($category, ['slug' => $slug]))
                ->assertRedirect(route('admin.categories.edit', $category))
                ->assertSessionHasErrors('slug');
        }

        $this->assertSame('current-slug', $category->fresh()->slug);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_duplicate_slug_is_rejected_when_conflict_is_soft_deleted(): void
    {
        $admin = User::factory()->admin()->create();
        $category = Category::factory()->create(['slug' => 'current-slug']);
        Category::factory()->trashed()->create(['slug' => 'reserved-slug']);

        $this->actingAs($admin)
            ->patch(route('admin.categories.update', $category), $this->payload($category, ['slug' => 'Reserved Slug']))
            ->assertSessionHasErrors('slug');

        $this->assertSame('current-slug', $category->fresh()->slug);
    }

    public function test_category_can_keep_its_own_slug(): void
    {
        $admin = User::factory()->admin()->create();
        $category = Category::factory()->create(['slug' => 'same-slug']);

        $this->actingAs($admin)
            ->patch(route('admin.categories.update', $category), $this->payload($category, [
                'slug' => ' SAME Slug ',
                'name_en' => 'Changed Name',
            ]))->assertRedirect(route('admin.categories.index'));

        $this->assertSame('same-slug', $category->fresh()->slug);
    }

    public function test_display_order_boundaries_and_boolean_input_are_validated(): void
    {
        $admin = User::factory()->admin()->create();
        $category = Category::factory()->create();

        foreach ([-1, 4294967296, '1.5'] as $invalidOrder) {
            $this->actingAs($admin)
                ->patch(route('admin.categories.update', $category), $this->payload($category, ['display_order' => $invalidOrder]))
                ->assertSessionHasErrors('display_order');
        }

        $this->patch(route('admin.categories.update', $category), $this->payload($category, ['is_active' => 'yes']))
            ->assertSessionHasErrors('is_active');

        foreach ([0, 4294967295] as $validOrder) {
            $this->patch(route('admin.categories.update', $category), $this->payload($category, ['display_order' => $validOrder]))
                ->assertRedirect(route('admin.categories.index'));
        }
    }

    public function test_active_state_can_transition_in_both_directions(): void
    {
        $admin = User::factory()->admin()->create();
        $category = Category::factory()->create();

        $this->actingAs($admin)->patch(route('admin.categories.update', $category), $this->payload($category, ['is_active' => '0']));
        $this->assertFalse($category->fresh()->is_active);

        $this->patch(route('admin.categories.update', $category), $this->payload($category->fresh(), ['is_active' => '1']));
        $this->assertTrue($category->fresh()->is_active);
    }

    public function test_injected_fields_cannot_change_protected_values(): void
    {
        $creator = User::factory()->admin()->create();
        $updater = User::factory()->admin()->create();
        $other = User::factory()->superAdmin()->create();
        $category = Category::factory()->create([
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
            'icon' => 'existing-icon',
            'image_path' => 'existing/image.jpg',
        ]);

        $this->actingAs($updater)->patch(route('admin.categories.update', $category), [
            ...$this->payload($category, ['name_en' => 'Approved Change']),
            'id' => 999,
            'created_by' => $other->id,
            'updated_by' => $other->id,
            'deleted_at' => now(),
            'icon' => 'injected-icon',
            'image_path' => 'injected/image.jpg',
        ])->assertRedirect(route('admin.categories.index'));

        $category->refresh();
        $this->assertNotSame(999, $category->id);
        $this->assertSame($creator->id, $category->created_by);
        $this->assertSame($updater->id, $category->updated_by);
        $this->assertNull($category->deleted_at);
        $this->assertSame('existing-icon', $category->icon);
        $this->assertSame('existing/image.jpg', $category->image_path);
    }

    public function test_audit_has_safe_states_and_changed_field_names_only(): void
    {
        $admin = User::factory()->admin()->create();
        $category = Category::factory()->atPosition(2)->create([
            'name_ar' => 'قديم',
            'name_en' => 'Old Name',
            'slug' => 'old-name',
            'description_ar' => 'Old private Arabic description',
            'description_en' => 'Old private English description',
        ]);

        $this->actingAs($admin)->patch(route('admin.categories.update', $category), $this->payload($category, [
            'name_ar' => 'جديد',
            'name_en' => 'New Name',
            'slug' => 'new-name',
            'description_en' => 'New private English description',
            'display_order' => 7,
            'is_active' => '0',
        ]));

        $audit = AuditLog::query()->sole();
        $this->assertSame('category.updated', $audit->action);
        $this->assertSame($admin->id, $audit->actor_id);
        $this->assertSame($category->id, $audit->subject_id);
        $this->assertSame([
            'name_ar' => 'قديم',
            'name_en' => 'Old Name',
            'slug' => 'old-name',
            'is_active' => true,
            'display_order' => 2,
        ], $audit->old_values);
        $this->assertSame([
            'name_ar' => 'جديد',
            'name_en' => 'New Name',
            'slug' => 'new-name',
            'is_active' => false,
            'display_order' => 7,
            'changed_fields' => [
                'name_ar', 'name_en', 'slug', 'description_en', 'display_order', 'is_active',
            ],
        ], $audit->new_values);
        $encoded = json_encode($audit->getAttributes());
        $this->assertStringNotContainsString('private', $encoded);
    }

    public function test_description_only_update_is_audited_without_description_contents(): void
    {
        $admin = User::factory()->admin()->create();
        $category = Category::factory()->create(['description_en' => 'Old secret description']);

        $this->actingAs($admin)->patch(route('admin.categories.update', $category), $this->payload($category, [
            'description_en' => 'New secret description',
        ]));

        $audit = AuditLog::query()->sole();
        $this->assertSame(['description_en'], $audit->new_values['changed_fields']);
        $encoded = json_encode($audit->getAttributes());
        $this->assertStringNotContainsString('Old secret description', $encoded);
        $this->assertStringNotContainsString('New secret description', $encoded);
    }

    public function test_no_op_update_does_not_save_or_audit(): void
    {
        $originalUpdater = User::factory()->admin()->create();
        $actor = User::factory()->admin()->create();
        $category = Category::factory()->create(['updated_by' => $originalUpdater->id]);
        $originalUpdatedAt = $category->updated_at;

        $this->actingAs($actor)->patch(route('admin.categories.update', $category), $this->payload($category))
            ->assertRedirect(route('admin.categories.index'));

        $category->refresh();
        $this->assertSame($originalUpdater->id, $category->updated_by);
        $this->assertTrue($originalUpdatedAt->equalTo($category->updated_at));
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_validation_failure_changes_nothing_and_writes_no_audit(): void
    {
        $admin = User::factory()->admin()->create();
        $category = Category::factory()->create();
        $original = $category->fresh()->getAttributes();

        $this->actingAs($admin)->patch(route('admin.categories.update', $category), [
            ...$this->payload($category),
            'name_ar' => '',
            'description_en' => str_repeat('x', 5001),
        ])->assertSessionHasErrors(['name_ar', 'description_en']);

        $this->assertSame($original, $category->fresh()->getAttributes());
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_audit_failure_rolls_back_all_changes(): void
    {
        $admin = User::factory()->admin()->create();
        $category = Category::factory()->create();
        $original = $category->fresh()->getAttributes();
        $this->mock(AuditLogger::class)
            ->shouldReceive('log')
            ->once()
            ->andThrow(new RuntimeException('Audit storage failed.'));
        $this->withoutExceptionHandling();

        try {
            $this->actingAs($admin)->patch(route('admin.categories.update', $category), $this->payload($category, [
                'name_en' => 'Must Roll Back',
                'display_order' => 99,
                'is_active' => '0',
            ]));
            $this->fail('The audit failure should escape the request.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit storage failed.', $exception->getMessage());
        }

        $this->assertSame($original, $category->fresh()->getAttributes());
    }

    public function test_concurrent_duplicate_slug_returns_safe_validation_error(): void
    {
        $admin = User::factory()->admin()->create();
        $category = Category::factory()->create(['slug' => 'original-slug']);
        DB::statement("CREATE TRIGGER concurrent_category_update BEFORE UPDATE ON categories WHEN NEW.slug = 'race-slug' BEGIN SELECT RAISE(ABORT, 'UNIQUE constraint failed: categories.slug'); END");

        $this->actingAs($admin)
            ->from(route('admin.categories.edit', $category))
            ->patch(route('admin.categories.update', $category), $this->payload($category, ['slug' => 'race-slug']))
            ->assertRedirect(route('admin.categories.edit', $category))
            ->assertSessionHasErrors(['slug' => 'This category slug is already in use.']);

        $this->assertSame('original-slug', $category->fresh()->slug);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_stale_category_instance_cannot_bypass_locked_current_lookup(): void
    {
        $admin = User::factory()->admin()->create();
        $staleCategory = Category::factory()->create();
        DB::table('categories')->where('id', $staleCategory->id)->update(['deleted_at' => now()]);

        $this->expectException(ModelNotFoundException::class);

        app(CategoryUpdateService::class)->update(
            $admin,
            $staleCategory,
            $this->payload($staleCategory, ['name_en' => 'Forbidden stale update']),
            Request::create('/admin/categories/'.$staleCategory->id, 'PATCH'),
        );
    }

    public function test_index_edit_controls_follow_policy_authorization(): void
    {
        $normal = User::factory()->user()->create();
        $admin = User::factory()->admin()->create();
        $category = Category::factory()->create();

        $this->assertFalse($normal->can('update', $category));
        $this->assertTrue($admin->can('update', $category));
        $this->actingAs($admin)->get(route('admin.categories.index'))
            ->assertOk()
            ->assertSee(route('admin.categories.edit', $category));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(Category $category, array $overrides = []): array
    {
        return array_merge([
            'name_ar' => $category->name_ar,
            'name_en' => $category->name_en,
            'slug' => $category->slug,
            'description_ar' => $category->description_ar,
            'description_en' => $category->description_en,
            'display_order' => $category->display_order,
            'is_active' => $category->is_active ? '1' : '0',
        ], $overrides);
    }
}
