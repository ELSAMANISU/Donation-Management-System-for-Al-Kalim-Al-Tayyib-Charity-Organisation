<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CategoryImageService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class CategoryImageManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_for_upload_and_removal(): void
    {
        Storage::fake('public');
        $category = Category::factory()->create();

        $this->patch(route('admin.categories.image.update', $category), ['image' => $this->image()])
            ->assertRedirect(route('login'));
        $this->delete(route('admin.categories.image.destroy', $category))
            ->assertRedirect(route('login'));
    }

    public function test_normal_user_is_forbidden_for_upload_and_removal(): void
    {
        Storage::fake('public');
        $user = User::factory()->user()->create();
        $category = Category::factory()->create();

        $this->actingAs($user)
            ->patch(route('admin.categories.image.update', $category), ['image' => $this->image()])
            ->assertForbidden();
        $this->delete(route('admin.categories.image.destroy', $category))->assertForbidden();
    }

    public function test_admin_and_super_admin_can_upload_replace_and_remove(): void
    {
        foreach ([User::factory()->admin()->create(), User::factory()->superAdmin()->create()] as $actor) {
            Storage::fake('public');
            $category = Category::factory()->create();

            $this->actingAs($actor)->patch(route('admin.categories.image.update', $category), [
                'image' => $this->image('first.png'),
            ])->assertRedirect(route('admin.categories.edit', $category));
            $firstPath = $category->fresh()->image_path;
            Storage::disk('public')->assertExists($firstPath);

            $this->patch(route('admin.categories.image.update', $category), [
                'image' => $this->image('second.webp'),
            ])->assertRedirect(route('admin.categories.edit', $category));
            $secondPath = $category->fresh()->image_path;
            $this->assertNotSame($firstPath, $secondPath);
            Storage::disk('public')->assertMissing($firstPath);
            Storage::disk('public')->assertExists($secondPath);

            $this->delete(route('admin.categories.image.destroy', $category))
                ->assertRedirect(route('admin.categories.edit', $category));
            $this->assertNull($category->fresh()->image_path);
            Storage::disk('public')->assertMissing($secondPath);
        }
    }

    public function test_disabled_and_password_change_pending_administrators_are_blocked(): void
    {
        Storage::fake('public');
        $category = Category::factory()->create();
        $disabled = User::factory()->admin()->disabled()->create();

        $this->actingAs($disabled)
            ->patch(route('admin.categories.image.update', $category), ['image' => $this->image()])
            ->assertRedirect(route('login'));
        $this->delete(route('admin.categories.image.destroy', $category))->assertRedirect(route('login'));

        $pending = User::factory()->admin()->mustChangePassword()->create();
        $this->actingAs($pending)
            ->patch(route('admin.categories.image.update', $category), ['image' => $this->image()])
            ->assertRedirect(route('password.change.required.edit'));
        $this->delete(route('admin.categories.image.destroy', $category))
            ->assertRedirect(route('password.change.required.edit'));

        $this->assertNull($category->fresh()->image_path);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_soft_deleted_categories_return_not_found(): void
    {
        Storage::fake('public');
        $admin = User::factory()->admin()->create();
        $category = Category::factory()->trashed()->create();

        $this->actingAs($admin)
            ->patch(route('admin.categories.image.update', $category->id), ['image' => $this->image()])
            ->assertNotFound();
        $this->delete(route('admin.categories.image.destroy', $category->id))->assertNotFound();
    }

    public function test_jpeg_png_and_webp_uploads_are_validated_and_stored_safely(): void
    {
        foreach (['jpeg', 'png', 'webp'] as $extension) {
            Storage::fake('public');
            $admin = User::factory()->admin()->create();
            $category = Category::factory()->create();
            $originalName = 'private-original-name.'.$extension;

            $this->actingAs($admin)->patch(route('admin.categories.image.update', $category), [
                'image' => $this->image($originalName),
                'image_path' => '../injected.svg',
                'updated_by' => 999,
            ])->assertRedirect(route('admin.categories.edit', $category));

            $category->refresh();
            $this->assertMatchesRegularExpression('/\Acategories\/[0-9a-f-]{36}\.(?:jpg|png|webp)\z/', $category->image_path);
            $this->assertStringNotContainsString('private-original-name', $category->image_path);
            $this->assertStringNotContainsString('..', $category->image_path);
            $this->assertSame($admin->id, $category->updated_by);
            Storage::disk('public')->assertExists($category->image_path);
        }
    }

    public function test_upload_preserves_creator_and_ignores_all_injected_fields(): void
    {
        Storage::fake('public');
        $creator = User::factory()->admin()->create();
        $updater = User::factory()->admin()->create();
        $category = Category::factory()->create(['created_by' => $creator->id]);

        $this->actingAs($updater)->patch(route('admin.categories.image.update', $category), [
            'image' => $this->image(),
            'image_path' => 'categories/client.png',
            'created_by' => $updater->id,
            'updated_by' => $creator->id,
            'id' => 999,
            'name_en' => 'Injected Name',
        ]);

        $category->refresh();
        $this->assertSame($creator->id, $category->created_by);
        $this->assertSame($updater->id, $category->updated_by);
        $this->assertNotSame(999, $category->id);
        $this->assertNotSame('Injected Name', $category->name_en);
        $this->assertNotSame('categories/client.png', $category->image_path);
    }

    public function test_invalid_files_extensions_mime_size_and_dimensions_are_rejected(): void
    {
        Storage::fake('public');
        $admin = User::factory()->admin()->create();
        $category = Category::factory()->create();
        $files = [
            UploadedFile::fake()->createWithContent('fake.jpg', 'not an image'),
            UploadedFile::fake()->image('vector.svg'),
            UploadedFile::fake()->image('animation.gif'),
            UploadedFile::fake()->create('document.pdf', 10, 'application/pdf'),
            UploadedFile::fake()->image('mismatch.jpg')->mimeType('image/png'),
            UploadedFile::fake()->image('large.png')->size(5121),
            UploadedFile::fake()->image('wide.png', 8001, 1),
            UploadedFile::fake()->image('tall.png', 1, 8001),
        ];

        foreach ($files as $file) {
            $this->actingAs($admin)
                ->patch(route('admin.categories.image.update', $category), ['image' => $file])
                ->assertSessionHasErrors('image');
        }

        $this->assertNull($category->fresh()->image_path);
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_replacement_deletes_old_managed_image_only_after_success(): void
    {
        Storage::fake('public');
        $admin = User::factory()->admin()->create();
        $oldPath = 'categories/old-safe-image.jpg';
        Storage::disk('public')->put($oldPath, 'old image');
        $category = Category::factory()->create(['image_path' => $oldPath]);

        $this->actingAs($admin)->patch(route('admin.categories.image.update', $category), [
            'image' => $this->image('replacement.png'),
        ]);

        $newPath = $category->fresh()->image_path;
        Storage::disk('public')->assertMissing($oldPath);
        Storage::disk('public')->assertExists($newPath);
    }

    public function test_failed_replacement_preserves_old_path_and_file_and_cleans_new_file(): void
    {
        Storage::fake('public');
        $admin = User::factory()->admin()->create();
        $oldPath = 'categories/existing-safe.jpg';
        Storage::disk('public')->put($oldPath, 'old image');
        $category = Category::factory()->create(['image_path' => $oldPath]);
        $this->mock(AuditLogger::class)->shouldReceive('log')->once()->andThrow(new RuntimeException('Audit failed.'));
        $this->withoutExceptionHandling();

        try {
            $this->actingAs($admin)->patch(route('admin.categories.image.update', $category), [
                'image' => $this->image('new-image.png'),
            ]);
            $this->fail('The audit failure should escape.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit failed.', $exception->getMessage());
        }

        $this->assertSame($oldPath, $category->fresh()->image_path);
        Storage::disk('public')->assertExists($oldPath);
        $this->assertSame([$oldPath], Storage::disk('public')->allFiles('categories'));
    }

    public function test_unexpected_storage_return_cleans_only_server_generated_expected_path(): void
    {
        Storage::fake('public');
        $admin = User::factory()->admin()->create();
        $originalPath = 'categories/original-managed.jpg';
        $unexpectedPath = 'unexpected/adapter-returned.jpg';
        $realDisk = Storage::disk('public');
        $realDisk->put($originalPath, 'original category image');
        $realDisk->put($unexpectedPath, 'unrelated unexpected file');
        $category = Category::factory()->create(['image_path' => $originalPath]);
        $expectedPath = null;
        $deletedPaths = [];
        $mockDisk = Mockery::mock($realDisk)->makePartial();
        $mockDisk->shouldReceive('putFileAs')
            ->once()
            ->andReturnUsing(function (string $directory, UploadedFile $file, string $filename) use ($realDisk, $unexpectedPath, &$expectedPath): string {
                $expectedPath = $directory.'/'.$filename;
                $realDisk->putFileAs($directory, $file, $filename);

                return $unexpectedPath;
            });
        $mockDisk->shouldReceive('delete')
            ->andReturnUsing(function (string $path) use ($realDisk, &$deletedPaths): bool {
                $deletedPaths[] = $path;

                return $realDisk->delete($path);
            });
        Storage::shouldReceive('disk')->with('public')->andReturn($mockDisk);

        $response = $this->actingAs($admin)
            ->from(route('admin.categories.edit', $category))
            ->patch(route('admin.categories.image.update', $category), [
                'image' => $this->image('adapter-edge-case.png'),
            ]);

        $response->assertRedirect(route('admin.categories.edit', $category))
            ->assertSessionHasErrors([
                'image' => 'The category image could not be stored. Please try again.',
            ]);
        $this->assertIsString($expectedPath);
        $this->assertSame([$expectedPath], $deletedPaths);
        $this->assertFalse($realDisk->exists($expectedPath));
        $this->assertTrue($realDisk->exists($unexpectedPath));
        $this->assertSame($originalPath, $category->fresh()->image_path);
        $this->assertDatabaseCount('audit_logs', 0);

        $validationMessage = session('errors')->first('image');
        $this->assertStringNotContainsString($expectedPath, $validationMessage);
        $this->assertStringNotContainsString($unexpectedPath, $validationMessage);
        $this->assertStringNotContainsString('storage', strtolower($validationMessage));
    }

    public function test_removal_is_safe_and_repeated_removal_is_idempotent(): void
    {
        Storage::fake('public');
        $actor = User::factory()->admin()->create();
        $originalUpdater = User::factory()->admin()->create();
        $path = 'categories/removable-image.webp';
        Storage::disk('public')->put($path, 'image');
        Storage::disk('public')->put('unrelated/keep.jpg', 'keep');
        $category = Category::factory()->create(['image_path' => $path, 'updated_by' => $originalUpdater->id]);

        $this->actingAs($actor)->delete(route('admin.categories.image.destroy', $category))
            ->assertRedirect(route('admin.categories.edit', $category));
        $category->refresh();
        $firstUpdatedAt = $category->updated_at;
        $this->assertNull($category->image_path);
        $this->assertSame($actor->id, $category->updated_by);
        Storage::disk('public')->assertMissing($path);
        Storage::disk('public')->assertExists('unrelated/keep.jpg');
        $this->assertDatabaseCount('audit_logs', 1);

        $this->delete(route('admin.categories.image.destroy', $category))
            ->assertRedirect(route('admin.categories.edit', $category));
        $category->refresh();
        $this->assertTrue($firstUpdatedAt->equalTo($category->updated_at));
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_unsafe_existing_path_is_cleared_but_never_deleted(): void
    {
        Storage::fake('public');
        $admin = User::factory()->admin()->create();
        Storage::disk('public')->put('outside.jpg', 'must remain');
        $category = Category::factory()->create(['image_path' => '../outside.jpg']);

        $this->actingAs($admin)->delete(route('admin.categories.image.destroy', $category));

        $this->assertNull($category->fresh()->image_path);
        Storage::disk('public')->assertExists('outside.jpg');
    }

    public function test_old_file_deletion_failure_is_best_effort_after_commit_without_path_logging(): void
    {
        Storage::fake('public');
        $admin = User::factory()->admin()->create();
        $path = 'categories/orphaned-image.jpg';
        Storage::disk('public')->put($path, 'image');
        $category = Category::factory()->create(['image_path' => $path]);
        $disk = Storage::disk('public');
        $mockDisk = Mockery::mock($disk)->makePartial();
        $mockDisk->shouldReceive('delete')->once()->with($path)->andReturn(false);
        Storage::shouldReceive('disk')->with('public')->andReturn($mockDisk);
        Log::spy();

        $this->actingAs($admin)->delete(route('admin.categories.image.destroy', $category))
            ->assertRedirect(route('admin.categories.edit', $category));

        $this->assertNull($category->fresh()->image_path);
        $this->assertTrue($disk->exists($path));
        Log::shouldHaveReceived('warning')->once()->with(
            'Managed category image cleanup failed after a storage operation.',
            ['category_id' => $category->id],
        );
    }

    public function test_audits_use_correct_actions_and_safe_boolean_state_only(): void
    {
        Storage::fake('public');
        $admin = User::factory()->admin()->create();
        $category = Category::factory()->create(['description_en' => 'private-description-marker']);

        $this->actingAs($admin)->patch(route('admin.categories.image.update', $category), [
            'image' => $this->image('original-secret.png'),
            'image_path' => 'injected/path.png',
        ]);
        $this->patch(route('admin.categories.image.update', $category), [
            'image' => $this->image('replacement-secret.jpg'),
        ]);
        $this->delete(route('admin.categories.image.destroy', $category));

        $audits = AuditLog::query()->orderBy('id')->get();
        $this->assertSame([
            'category.image_uploaded', 'category.image_replaced', 'category.image_removed',
        ], $audits->pluck('action')->all());

        foreach ($audits as $audit) {
            $this->assertSame($admin->id, $audit->actor_id);
            $this->assertSame($category->id, $audit->subject_id);
            $this->assertSame(['had_image', 'has_image'], array_keys($audit->old_values));
            $this->assertSame(['had_image', 'has_image'], array_keys($audit->new_values));
            $encoded = json_encode($audit->getAttributes());
            $this->assertStringNotContainsString('secret', $encoded);
            $this->assertStringNotContainsString('categories/', $encoded);
            $this->assertStringNotContainsString('private-description-marker', $encoded);
            $this->assertStringNotContainsString('injected', $encoded);
        }
    }

    public function test_stale_category_state_cannot_bypass_locked_lookup_and_new_file_is_cleaned(): void
    {
        Storage::fake('public');
        $admin = User::factory()->admin()->create();
        $staleCategory = Category::factory()->create();
        DB::table('categories')->where('id', $staleCategory->id)->update(['deleted_at' => now()]);

        try {
            app(CategoryImageService::class)->upload(
                $admin,
                $staleCategory,
                $this->image(),
                Request::create('/admin/categories/'.$staleCategory->id.'/image', 'PATCH'),
            );
            $this->fail('The stale category must not be updated.');
        } catch (ModelNotFoundException) {
            $this->assertSame([], Storage::disk('public')->allFiles());
        }
    }

    public function test_index_and_edit_render_safe_previews_forms_and_no_image_state(): void
    {
        Storage::fake('public');
        $admin = User::factory()->admin()->create();
        $withImage = Category::factory()->create([
            'name_en' => '<script>unsafe name</script>',
            'image_path' => 'categories/safe-preview.png',
        ]);
        $withoutImage = Category::factory()->create(['image_path' => null]);

        $this->actingAs($admin)->get(route('admin.categories.index'))
            ->assertOk()
            ->assertSee(Storage::disk('public')->url($withImage->image_path))
            ->assertSeeText('No image / لا توجد صورة')
            ->assertSee($withImage->name_en)
            ->assertDontSee($withImage->name_en, false);

        $this->get(route('admin.categories.edit', $withImage))
            ->assertOk()
            ->assertSee('enctype="multipart/form-data"', false)
            ->assertSee('accept="image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp"', false)
            ->assertSee(route('admin.categories.image.destroy', $withImage))
            ->assertDontSee('name="image_path"', false);

        $this->get(route('admin.categories.edit', $withoutImage))
            ->assertOk()
            ->assertSeeText('No image / لا توجد صورة')
            ->assertDontSeeText('Remove Image / إزالة الصورة');
    }

    private function image(string $name = 'category-image.jpg'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 40, 40);
    }
}
