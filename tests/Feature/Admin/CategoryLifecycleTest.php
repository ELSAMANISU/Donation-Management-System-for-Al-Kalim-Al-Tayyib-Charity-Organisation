<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CategoryLifecycleService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class CategoryLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_for_trashed_delete_and_restore(): void
    {
        $active = Category::factory()->create();
        $trashed = Category::factory()->trashed()->create();

        $this->get(route('admin.categories.trashed'))->assertRedirect(route('login'));
        $this->delete(route('admin.categories.destroy', $active))->assertRedirect(route('login'));
        $this->patch(route('admin.categories.restore', $trashed->id))->assertRedirect(route('login'));
    }

    public function test_normal_user_is_forbidden_for_trashed_delete_and_restore(): void
    {
        $user = User::factory()->user()->create();
        $active = Category::factory()->create();
        $trashed = Category::factory()->trashed()->create();

        $this->actingAs($user)->get(route('admin.categories.trashed'))->assertForbidden();
        $this->delete(route('admin.categories.destroy', $active))->assertForbidden();
        $this->patch(route('admin.categories.restore', $trashed->id))->assertForbidden();
    }

    public function test_admin_and_super_admin_can_view_delete_and_restore(): void
    {
        foreach ([UserRole::Admin, UserRole::SuperAdmin] as $role) {
            $actor = User::factory()->create(['role' => $role]);
            $category = Category::factory()->create();

            $this->actingAs($actor)->get(route('admin.categories.trashed'))->assertOk();
            $this->delete(route('admin.categories.destroy', $category))
                ->assertRedirect(route('admin.categories.index'));
            $this->assertTrue($category->fresh()->trashed());

            $this->patch(route('admin.categories.restore', $category->id))
                ->assertRedirect(route('admin.categories.index'));
            $this->assertFalse($category->fresh()->trashed());
        }
    }

    public function test_disabled_and_password_change_pending_administrators_are_blocked(): void
    {
        $active = Category::factory()->create();
        $trashed = Category::factory()->trashed()->create();
        $disabled = User::factory()->admin()->disabled()->create();

        $this->actingAs($disabled)->get(route('admin.categories.trashed'))
            ->assertRedirect(route('login'));
        $this->delete(route('admin.categories.destroy', $active))->assertRedirect(route('login'));
        $this->patch(route('admin.categories.restore', $trashed->id))->assertRedirect(route('login'));

        $pending = User::factory()->admin()->mustChangePassword()->create();
        $this->actingAs($pending)->get(route('admin.categories.trashed'))
            ->assertRedirect(route('password.change.required.edit'));
        $this->delete(route('admin.categories.destroy', $active))
            ->assertRedirect(route('password.change.required.edit'));
        $this->patch(route('admin.categories.restore', $trashed->id))
            ->assertRedirect(route('password.change.required.edit'));

        $this->assertNotNull(Category::find($active->id));
        $this->assertTrue(Category::onlyTrashed()->findOrFail($trashed->id)->trashed());
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_soft_delete_preserves_row_state_and_managed_image_file(): void
    {
        Storage::fake('public');
        $creator = User::factory()->admin()->create();
        $actor = User::factory()->admin()->create();
        $path = 'categories/preserved-lifecycle-image.jpg';
        Storage::disk('public')->put($path, 'preserved image');
        $category = Category::factory()->inactive()->atPosition(27)->create([
            'name_ar' => 'اسم محفوظ',
            'name_en' => 'Preserved Name',
            'slug' => 'preserved-slug',
            'description_ar' => 'وصف محفوظ',
            'description_en' => 'Preserved description',
            'image_path' => $path,
            'icon' => 'preserved-icon',
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
        ]);

        $this->actingAs($actor)->delete(route('admin.categories.destroy', $category))
            ->assertRedirect(route('admin.categories.index'))
            ->assertSessionHas('status', 'category-deleted');

        $this->assertNull(Category::find($category->id));
        $deleted = Category::withTrashed()->findOrFail($category->id);
        $this->assertTrue($deleted->trashed());
        $this->assertSame('اسم محفوظ', $deleted->name_ar);
        $this->assertSame('Preserved Name', $deleted->name_en);
        $this->assertSame('preserved-slug', $deleted->slug);
        $this->assertSame('وصف محفوظ', $deleted->description_ar);
        $this->assertSame('Preserved description', $deleted->description_en);
        $this->assertFalse($deleted->is_active);
        $this->assertSame(27, $deleted->display_order);
        $this->assertSame($path, $deleted->image_path);
        $this->assertSame('preserved-icon', $deleted->icon);
        $this->assertSame($creator->id, $deleted->created_by);
        $this->assertSame($actor->id, $deleted->updated_by);
        Storage::disk('public')->assertExists($path);

        $this->actingAs($actor)->get(route('admin.categories.index'))
            ->assertOk()
            ->assertDontSee('Preserved Name');
    }

    public function test_repeated_delete_and_restore_of_wrong_state_return_not_found_without_audit(): void
    {
        $admin = User::factory()->admin()->create();
        $deleted = Category::factory()->trashed()->create();
        $active = Category::factory()->create();

        $this->actingAs($admin)->delete(route('admin.categories.destroy', $deleted->id))->assertNotFound();
        $this->patch(route('admin.categories.restore', $active->id))->assertNotFound();

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_delete_audit_failure_rolls_back_deletion_and_actor_change(): void
    {
        $originalUpdater = User::factory()->admin()->create();
        $actor = User::factory()->admin()->create();
        $category = Category::factory()->create(['updated_by' => $originalUpdater->id]);
        $this->mock(AuditLogger::class)->shouldReceive('log')->once()->andThrow(new RuntimeException('Audit failed.'));
        $this->withoutExceptionHandling();

        try {
            $this->actingAs($actor)->delete(route('admin.categories.destroy', $category));
            $this->fail('Audit failure should escape.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit failed.', $exception->getMessage());
        }

        $category->refresh();
        $this->assertFalse($category->trashed());
        $this->assertSame($originalUpdater->id, $category->updated_by);
    }

    public function test_stale_non_deleted_instance_cannot_bypass_locked_delete_lookup(): void
    {
        $admin = User::factory()->admin()->create();
        $stale = Category::factory()->create();
        DB::table('categories')->where('id', $stale->id)->update(['deleted_at' => now()]);

        $this->expectException(ModelNotFoundException::class);
        app(CategoryLifecycleService::class)->delete(
            $admin,
            $stale,
            Request::create('/admin/categories/'.$stale->id, 'DELETE'),
        );
    }

    public function test_trashed_index_projection_order_pagination_escaping_and_empty_state(): void
    {
        $admin = User::factory()->admin()->create();
        $unsafe = '<script>alert("trashed")</script>';
        $oldest = Category::factory()->trashed()->create([
            'name_en' => 'Oldest',
            'deleted_at' => now()->subDays(2),
        ]);
        $newerFirst = Category::factory()->trashed()->create([
            'name_ar' => $unsafe,
            'name_en' => 'Newer First',
            'description_en' => 'private-description',
            'image_path' => 'categories/private-image.jpg',
            'created_by' => $admin->id,
            'deleted_at' => now()->subDay(),
        ]);
        $newerSecond = Category::factory()->trashed()->create([
            'name_en' => 'Newer Second',
            'deleted_at' => now()->subDay(),
        ]);
        Category::factory()->count(13)->trashed()->create(['deleted_at' => now()->subDays(3)]);
        $active = Category::factory()->create(['name_en' => 'Active Excluded']);

        $this->actingAs($admin)->get(route('admin.categories.trashed'))
            ->assertOk()
            ->assertViewHas('categories', function ($categories) use ($newerFirst, $newerSecond): bool {
                $first = $categories->first();

                return $categories->perPage() === 15
                    && $categories->total() === 16
                    && $categories->pluck('id')->take(2)->all() === [$newerSecond->id, $newerFirst->id]
                    && array_keys($first->getAttributes()) === [
                        'id', 'name_ar', 'name_en', 'slug', 'is_active', 'display_order', 'deleted_at',
                    ];
            })
            ->assertSee($unsafe)
            ->assertDontSee($unsafe, false)
            ->assertDontSee($oldest->description_en ?? 'never')
            ->assertDontSee('private-description')
            ->assertDontSee('private-image.jpg')
            ->assertDontSee($active->name_en)
            ->assertDontSee('Force Delete')
            ->assertSee('page=2', false);

        Category::onlyTrashed()->restore();
        $this->get(route('admin.categories.trashed'))
            ->assertOk()
            ->assertSeeText('No deleted categories. / لا توجد فئات محذوفة.');
    }

    public function test_restore_preserves_same_row_state_and_image_without_reactivation(): void
    {
        Storage::fake('public');
        $creator = User::factory()->admin()->create();
        $actor = User::factory()->admin()->create();
        $path = 'categories/restored-image.webp';
        Storage::disk('public')->put($path, 'preserved image');
        $category = Category::factory()->inactive()->atPosition(31)->trashed()->create([
            'name_ar' => 'فئة مستعادة',
            'name_en' => 'Restored Category',
            'slug' => 'restored-category',
            'description_ar' => 'وصف عربي',
            'description_en' => 'English description',
            'image_path' => $path,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
        ]);

        $this->actingAs($actor)->patch(route('admin.categories.restore', $category->id))
            ->assertRedirect(route('admin.categories.index'))
            ->assertSessionHas('status', 'category-restored');

        $restored = Category::findOrFail($category->id);
        $this->assertSame($category->id, $restored->id);
        $this->assertSame('فئة مستعادة', $restored->name_ar);
        $this->assertSame('Restored Category', $restored->name_en);
        $this->assertSame('restored-category', $restored->slug);
        $this->assertSame('وصف عربي', $restored->description_ar);
        $this->assertSame('English description', $restored->description_en);
        $this->assertFalse($restored->is_active);
        $this->assertSame(31, $restored->display_order);
        $this->assertSame($path, $restored->image_path);
        $this->assertSame($creator->id, $restored->created_by);
        $this->assertSame($actor->id, $restored->updated_by);
        $this->assertNull(Category::onlyTrashed()->find($category->id));
        Storage::disk('public')->assertExists($path);

        $this->get(route('admin.categories.index'))->assertSee('Restored Category');
        $this->get(route('admin.categories.trashed'))->assertDontSee('Restored Category');
    }

    public function test_restore_audit_failure_rolls_back_state_and_actor_change(): void
    {
        $originalUpdater = User::factory()->admin()->create();
        $actor = User::factory()->admin()->create();
        $category = Category::factory()->trashed()->create(['updated_by' => $originalUpdater->id]);
        $this->mock(AuditLogger::class)->shouldReceive('log')->once()->andThrow(new RuntimeException('Audit failed.'));
        $this->withoutExceptionHandling();

        try {
            $this->actingAs($actor)->patch(route('admin.categories.restore', $category->id));
            $this->fail('Audit failure should escape.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit failed.', $exception->getMessage());
        }

        $category = Category::onlyTrashed()->findOrFail($category->id);
        $this->assertTrue($category->trashed());
        $this->assertSame($originalUpdater->id, $category->updated_by);
    }

    public function test_stale_trashed_state_cannot_bypass_locked_restore_lookup(): void
    {
        $admin = User::factory()->admin()->create();
        $stale = Category::factory()->trashed()->create();
        DB::table('categories')->where('id', $stale->id)->update(['deleted_at' => null]);

        $this->expectException(ModelNotFoundException::class);
        app(CategoryLifecycleService::class)->restore(
            $admin,
            $stale->id,
            Request::create('/admin/categories/'.$stale->id.'/restore', 'PATCH'),
        );
    }

    public function test_lifecycle_audits_contain_only_safe_approved_state(): void
    {
        $admin = User::factory()->admin()->create();
        $category = Category::factory()->inactive()->atPosition(8)->create([
            'description_en' => 'private-description-marker',
            'image_path' => 'categories/private-file-marker.jpg',
        ]);

        $this->actingAs($admin)->delete(route('admin.categories.destroy', $category), [
            'image_path' => 'injected-path',
        ]);
        $this->patch(route('admin.categories.restore', $category->id), [
            'is_active' => true,
            'description_en' => 'injected-description',
        ]);

        $audits = AuditLog::query()->orderBy('id')->get();
        $this->assertSame(['category.deleted', 'category.restored'], $audits->pluck('action')->all());
        foreach ($audits as $audit) {
            $this->assertSame($admin->id, $audit->actor_id);
            $this->assertSame($category->id, $audit->subject_id);
            $this->assertSame(['was_deleted', 'is_deleted', 'is_active', 'display_order'], array_keys($audit->old_values));
            $this->assertSame(['was_deleted', 'is_deleted', 'is_active', 'display_order'], array_keys($audit->new_values));
            $this->assertFalse($audit->new_values['is_active']);
            $this->assertSame(8, $audit->new_values['display_order']);
            $encoded = json_encode($audit->getAttributes());
            $this->assertStringNotContainsString('private', $encoded);
            $this->assertStringNotContainsString('injected', $encoded);
            $this->assertStringNotContainsString('categories/', $encoded);
        }
        $this->assertFalse($audits[0]->old_values['was_deleted']);
        $this->assertTrue($audits[0]->new_values['is_deleted']);
        $this->assertTrue($audits[1]->old_values['was_deleted']);
        $this->assertFalse($audits[1]->new_values['is_deleted']);
    }

    public function test_policy_controls_are_present_only_for_authorized_actors(): void
    {
        $normal = User::factory()->user()->create();
        $admin = User::factory()->admin()->create();
        $active = Category::factory()->create();
        $trashed = Category::factory()->trashed()->create();

        $this->assertFalse($normal->can('delete', $active));
        $this->assertFalse($normal->can('restore', $trashed));
        $this->assertTrue($admin->can('delete', $active));
        $this->assertTrue($admin->can('restore', $trashed));

        $this->actingAs($admin)->get(route('admin.categories.index'))
            ->assertSee(route('admin.categories.trashed'))
            ->assertSee(route('admin.categories.destroy', $active));
        $this->get(route('admin.categories.trashed'))
            ->assertSee(route('admin.categories.restore', $trashed->id))
            ->assertDontSee('Force Delete');
    }
}
