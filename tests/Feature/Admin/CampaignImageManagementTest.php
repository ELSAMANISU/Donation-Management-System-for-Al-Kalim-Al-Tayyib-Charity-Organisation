<?php

namespace Tests\Feature\Admin;

use App\Enums\CampaignStatus;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CampaignImageService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class CampaignImageManagementTest extends TestCase
{
    use DatabaseMigrations;

    public function runDatabaseMigrations(): void
    {
        $connection = config('database.default');
        $database = config("database.connections.{$connection}.database");
        if ($connection !== 'sqlite' || $database !== ':memory:') {
            throw new LogicException('Campaign image tests may migrate only an in-memory SQLite database.');
        }

        $this->artisan('migrate:fresh');
        $this->app[Kernel::class]->setArtisan(null);
    }

    public function test_access_control_applies_to_preview_upload_and_removal(): void
    {
        Storage::fake('campaign_images');
        $campaign = Campaign::factory()->create();
        $routes = fn (Campaign $item): array => [
            ['get', route('admin.campaigns.image.show', $item), []],
            ['post', route('admin.campaigns.image.store', $item), $this->uploadPayload()],
            ['delete', route('admin.campaigns.image.destroy', $item), []],
        ];

        foreach ($routes($campaign) as [$method, $url, $data]) {
            $this->{$method}($url, $data)->assertRedirect(route('login'));
        }
        foreach ([User::factory()->user()->create()] as $actor) {
            foreach ($routes($campaign) as [$method, $url, $data]) {
                $this->actingAs($actor)->{$method}($url, $data)->assertForbidden();
            }
        }
        foreach ([User::factory()->admin()->disabled()->create(), User::factory()->admin()->mustChangePassword()->create()] as $actor) {
            foreach ($routes($campaign) as [$method, $url, $data]) {
                $this->actingAs($actor)->{$method}($url, $data)->assertRedirect();
            }
        }

        $this->assertNull($campaign->fresh()->image_path);
        $this->assertSame([], Storage::disk('campaign_images')->allFiles());
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_admin_and_super_admin_can_upload_preview_and_remove_a_draft_image(): void
    {
        foreach ([User::factory()->admin()->create(), User::factory()->superAdmin()->create()] as $actor) {
            Storage::fake('campaign_images');
            $campaign = Campaign::factory()->create();

            $this->actingAs($actor)->post(route('admin.campaigns.image.store', $campaign), $this->uploadPayload('private-name.png'))
                ->assertRedirect(route('admin.campaigns.edit', $campaign));
            $campaign->refresh();
            $this->assertMatchesRegularExpression('/\Acampaigns\/'.$campaign->id.'\/[0-9a-f-]{36}\.png\z/', $campaign->image_path);
            $this->assertStringNotContainsString('private-name', $campaign->image_path);
            Storage::disk('campaign_images')->assertExists($campaign->image_path);

            $this->get(route('admin.campaigns.image.show', $campaign))->assertOk()
                ->assertContent(Storage::disk('campaign_images')->get($campaign->image_path))
                ->assertHeader('Content-Type', 'image/png')
                ->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
                ->assertHeader('Pragma', 'no-cache')
                ->assertHeaderMissing('Content-Disposition');

            $path = $campaign->image_path;
            $this->delete(route('admin.campaigns.image.destroy', $campaign))->assertSessionHas('status', 'campaign-image-removed');
            $campaign->refresh();
            $this->assertNull($campaign->image_path);
            $this->assertNull($campaign->image_alt_ar);
            $this->assertNull($campaign->image_alt_en);
            Storage::disk('campaign_images')->assertMissing($path);
        }
    }

    public function test_non_draft_missing_and_soft_deleted_campaigns_are_rejected_for_every_route(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        Storage::fake('campaign_images');
        $admin = User::factory()->admin()->create();
        foreach (array_filter(CampaignStatus::cases(), fn (CampaignStatus $status) => $status !== CampaignStatus::Draft) as $status) {
            $campaign = Campaign::factory()->create(['status' => $status]);
            $snapshot = $campaign->fresh()->getAttributes();
            $this->actingAs($admin)->get(route('admin.campaigns.image.show', $campaign))->assertForbidden();
            $this->post(route('admin.campaigns.image.store', $campaign), $this->uploadPayload())->assertForbidden();
            $this->delete(route('admin.campaigns.image.destroy', $campaign))->assertForbidden();
            $this->assertSame($snapshot, $campaign->fresh()->getAttributes());
        }
        $trashed = Campaign::factory()->trashed()->create();
        foreach (['get', 'post', 'delete'] as $method) {
            $data = $method === 'post' ? $this->uploadPayload() : [];
            $this->actingAs($admin)->{$method}('/admin/campaigns/'.$trashed->slug.'/image', $data)->assertNotFound();
            $this->actingAs($admin)->{$method}('/admin/campaigns/missing/image', $data)->assertNotFound();
        }
        $this->assertSame([], Storage::disk('campaign_images')->allFiles());
        $this->assertDatabaseMissing('audit_logs', ['action' => 'campaign.image_uploaded']);
    }

    public function test_jpeg_png_and_webp_use_private_server_generated_owned_paths_and_ignore_injection(): void
    {
        foreach (['jpeg', 'png', 'webp'] as $extension) {
            Storage::fake('campaign_images');
            Storage::fake('public');
            $actor = User::factory()->admin()->create();
            $creator = User::factory()->admin()->create();
            $campaign = Campaign::factory()->create(['created_by' => $creator->id, 'target_amount' => '900.00']);
            $snapshot = $campaign->only(['slug', 'category_id', 'title_ar', 'title_en', 'summary_ar', 'summary_en', 'story_ar', 'story_en', 'target_amount', 'raised_amount', 'status', 'created_by']);

            $this->actingAs($actor)->post(route('admin.campaigns.image.store', $campaign), $this->uploadPayload('secret-original.'.$extension, [
                'image_path' => '../client.svg', 'updated_by' => $creator->id, 'status' => 'active', 'raised_amount' => '99.00', 'title_en' => 'Injected',
            ]))->assertSessionDoesntHaveErrors();

            $campaign->refresh();
            $this->assertTrue(Campaign::isManagedImagePath($campaign->image_path, $campaign->id));
            $this->assertMatchesRegularExpression('/\Acampaigns\/'.$campaign->id.'\/[0-9a-f-]{36}\.(?:jpg|png|webp)\z/', $campaign->image_path);
            $this->assertStringNotContainsString('secret-original', $campaign->image_path);
            $this->assertSame($snapshot, $campaign->only(array_keys($snapshot)));
            $this->assertSame($actor->id, $campaign->updated_by);
            $this->assertSame('Arabic alternative', $campaign->image_alt_ar);
            $this->assertSame('English alternative', $campaign->image_alt_en);
            Storage::disk('campaign_images')->assertExists($campaign->image_path);
            Storage::disk('public')->assertMissing($campaign->image_path);
        }
    }

    public function test_invalid_images_and_alternative_text_never_persist_or_audit(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        Storage::fake('campaign_images');
        $admin = User::factory()->admin()->create();
        $campaign = Campaign::factory()->create();
        $files = [
            UploadedFile::fake()->createWithContent('fake.jpg', 'executable text'),
            UploadedFile::fake()->image('vector.svg'), UploadedFile::fake()->image('animation.gif'),
            UploadedFile::fake()->create('document.pdf', 10, 'application/pdf'),
            UploadedFile::fake()->image('mismatch.jpg')->mimeType('image/png'),
            UploadedFile::fake()->image('large.png')->size(5121),
            UploadedFile::fake()->image('wide.png', 8001, 1), UploadedFile::fake()->image('tall.png', 1, 8001),
        ];
        foreach ($files as $file) {
            $this->actingAs($admin)->post(route('admin.campaigns.image.store', $campaign), $this->uploadPayload(file: $file))->assertSessionHasErrors('image');
        }
        foreach ([
            ['image_alt_ar' => ''], ['image_alt_en' => ''], ['image_alt_ar' => ['bad']], ['image_alt_en' => str_repeat('x', 256)],
        ] as $overrides) {
            $this->post(route('admin.campaigns.image.store', $campaign), $this->uploadPayload(overrides: $overrides))->assertSessionHasErrors(array_key_first($overrides));
        }
        $this->post(route('admin.campaigns.image.store', $campaign), $this->uploadPayload(overrides: ['image_alt_ar' => '  Arabic trimmed  ', 'image_alt_en' => '  English trimmed  ']))->assertSessionDoesntHaveErrors();
        $campaign->refresh();
        $this->assertSame('Arabic trimmed', $campaign->image_alt_ar);
        $this->assertSame('English trimmed', $campaign->image_alt_en);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_nested_transaction_is_rejected_before_any_file_write(): void
    {
        Storage::fake('campaign_images');
        $admin = User::factory()->admin()->create();
        foreach (['upload', 'remove'] as $operation) {
            $campaign = Campaign::factory()->create(['image_alt_ar' => 'Old Arabic', 'image_alt_en' => 'Old English']);
            $path = $this->managedPath($campaign, 'jpg');
            DB::table('campaigns')->where('id', $campaign->id)->update(['image_path' => $path]);
            Storage::disk('campaign_images')->put($path, 'owned bytes');
            $snapshot = $campaign->fresh()->getAttributes();
            DB::beginTransaction();
            try {
                if ($operation === 'upload') {
                    app(CampaignImageService::class)->upload($admin, $campaign, $this->image(), 'Arabic', 'English', Request::create('/', 'POST'));
                } else {
                    app(CampaignImageService::class)->remove($admin, $campaign, Request::create('/', 'DELETE'));
                }
                $this->fail('Nested '.$operation.' must fail.');
            } catch (LogicException $exception) {
                $this->assertSame('Campaign image mutations require a top-level database transaction.', $exception->getMessage());
            } finally {
                DB::rollBack();
            }
            $this->assertSame($snapshot, $campaign->fresh()->getAttributes());
            Storage::disk('campaign_images')->assertExists($path);
            $this->assertDatabaseCount('audit_logs', 0);
        }
    }

    public function test_locked_stale_states_fail_precisely_without_file_writes_or_audits(): void
    {
        foreach (['upload', 'remove'] as $operation) {
            foreach (['actor', 'password', 'status', 'deleted', 'raised'] as $case) {
                Storage::fake('campaign_images');
                $admin = User::factory()->admin()->create();
                $campaign = Campaign::factory()->create(['image_alt_ar' => 'Old Arabic', 'image_alt_en' => 'Old English']);
                $path = $this->managedPath($campaign, 'jpg');
                DB::table('campaigns')->where('id', $campaign->id)->update(['image_path' => $path]);
                Storage::disk('campaign_images')->put($path, 'owned bytes');
                if ($case === 'actor') {
                    DB::table('users')->where('id', $admin->id)->update(['is_active' => false]);
                }
                if ($case === 'password') {
                    DB::table('users')->where('id', $admin->id)->update(['must_change_password' => true]);
                }
                if ($case === 'status') {
                    DB::table('campaigns')->where('id', $campaign->id)->update(['status' => CampaignStatus::Active->value]);
                }
                if ($case === 'deleted') {
                    DB::table('campaigns')->where('id', $campaign->id)->update(['deleted_at' => now()]);
                }
                if ($case === 'raised') {
                    DB::table('campaigns')->where('id', $campaign->id)->update(['raised_amount' => '1.00']);
                }
                $snapshot = Campaign::withTrashed()->findOrFail($campaign->id)->getAttributes();

                try {
                    if ($operation === 'upload') {
                        app(CampaignImageService::class)->upload($admin, $campaign, $this->image(), 'Arabic', 'English', Request::create('/', 'POST'));
                    } else {
                        app(CampaignImageService::class)->remove($admin, $campaign, Request::create('/', 'DELETE'));
                    }
                    $this->fail('Stale mutation must fail.');
                } catch (AuthorizationException $exception) {
                    $this->assertContains($case, ['actor', 'password', 'status']);
                } catch (ModelNotFoundException $exception) {
                    $this->assertSame('deleted', $case);
                } catch (ValidationException $exception) {
                    $this->assertSame('raised', $case);
                    $this->assertSame(['image'], array_keys($exception->errors()));
                }
                $this->assertSame($snapshot, Campaign::withTrashed()->findOrFail($campaign->id)->getAttributes());
                if ($case === 'raised') {
                    $this->assertSame('1.00', Campaign::findOrFail($campaign->id)->raised_amount);
                }
                Storage::disk('campaign_images')->assertExists($path);
                $this->assertSame('owned bytes', Storage::disk('campaign_images')->get($path));
                $this->assertDatabaseCount('audit_logs', 0);
            }
        }
    }

    public function test_replacement_audit_failure_rolls_back_database_and_cleans_only_new_file(): void
    {
        Storage::fake('campaign_images');
        $admin = User::factory()->admin()->create();
        $campaign = Campaign::factory()->create(['image_alt_ar' => 'Old Arabic', 'image_alt_en' => 'Old English']);
        $oldPath = $this->managedPath($campaign, 'jpg');
        DB::table('campaigns')->where('id', $campaign->id)->update(['image_path' => $oldPath]);
        Storage::disk('campaign_images')->put($oldPath, 'old image');
        $snapshot = $campaign->fresh()->getAttributes();
        $this->mock(AuditLogger::class)->shouldReceive('log')->once()->andThrow(new RuntimeException('Audit failed.'));

        try {
            app(CampaignImageService::class)->upload($admin, $campaign, $this->image('new.png'), 'New Arabic', 'New English', Request::create('/', 'POST'));
            $this->fail('Audit failure must escape.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit failed.', $exception->getMessage());
        }
        $this->assertSame($snapshot, $campaign->fresh()->getAttributes());
        Storage::disk('campaign_images')->assertExists($oldPath);
        $this->assertSame([$oldPath], Storage::disk('campaign_images')->allFiles());
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_after_commit_callback_exception_preserves_committed_current_image_and_audit(): void
    {
        Storage::fake('campaign_images');
        $admin = User::factory()->admin()->create();
        $campaign = Campaign::factory()->create();
        $realLogger = new AuditLogger;
        $mockLogger = Mockery::mock($realLogger)->makePartial();
        $mockLogger->shouldReceive('log')->once()->andReturnUsing(function (
            string $action,
            ?User $actor,
            $subject,
            ?array $oldValues,
            ?array $newValues,
            ?Request $request,
        ) use ($realLogger) {
            $audit = $realLogger->log($action, $actor, $subject, $oldValues, $newValues, $request);
            DB::afterCommit(fn () => throw new RuntimeException('After commit callback failed.'));

            return $audit;
        });
        $this->app->instance(AuditLogger::class, $mockLogger);
        Log::spy();

        try {
            app(CampaignImageService::class)->upload($admin, $campaign, $this->image('committed.png'), 'Arabic', 'English', Request::create('/', 'POST'));
            $this->fail('The after-commit callback exception must escape.');
        } catch (RuntimeException $exception) {
            $this->assertSame('After commit callback failed.', $exception->getMessage());
        }

        $campaign->refresh();
        $this->assertTrue(Campaign::isManagedImagePath($campaign->image_path, $campaign->id));
        Storage::disk('campaign_images')->assertExists($campaign->image_path);
        $this->assertDatabaseHas('audit_logs', ['action' => 'campaign.image_uploaded', 'subject_id' => $campaign->id]);
        Log::shouldHaveReceived('warning')->once()->with('Managed Campaign image cleanup failed after a storage operation.', ['campaign_id' => $campaign->id]);
    }

    public function test_storage_false_preserves_database_and_creates_no_audit(): void
    {
        Storage::fake('campaign_images');
        $admin = User::factory()->admin()->create();
        $campaign = Campaign::factory()->create();
        $snapshot = $campaign->fresh()->getAttributes();
        $realDisk = Storage::disk('campaign_images');
        $mockDisk = Mockery::mock($realDisk)->makePartial();
        $mockDisk->shouldReceive('putFileAs')->once()->andReturn(false);
        $mockDisk->shouldReceive('delete')->once()->andReturn(true);
        Storage::shouldReceive('disk')->with('campaign_images')->andReturn($mockDisk);

        try {
            app(CampaignImageService::class)->upload($admin, $campaign, $this->image(), 'Arabic', 'English', Request::create('/', 'POST'));
            $this->fail('A false storage result must fail.');
        } catch (ValidationException $exception) {
            $this->assertSame(['image'], array_keys($exception->errors()));
        }
        $this->assertSame($snapshot, $campaign->fresh()->getAttributes());
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_storage_throw_cleans_partial_new_file_and_preserves_database(): void
    {
        Storage::fake('campaign_images');
        $admin = User::factory()->admin()->create();
        $campaign = Campaign::factory()->create();
        $snapshot = $campaign->fresh()->getAttributes();
        $realDisk = Storage::disk('campaign_images');
        $mockDisk = Mockery::mock($realDisk)->makePartial();
        $mockDisk->shouldReceive('putFileAs')->once()->andReturnUsing(function (string $directory, UploadedFile $file, string $filename) use ($realDisk): never {
            $realDisk->putFileAs($directory, $file, $filename);
            throw new RuntimeException('Storage failed.');
        });
        Storage::shouldReceive('disk')->with('campaign_images')->andReturn($mockDisk);

        try {
            app(CampaignImageService::class)->upload($admin, $campaign, $this->image(), 'Arabic', 'English', Request::create('/', 'POST'));
            $this->fail('A storage exception must escape.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Storage failed.', $exception->getMessage());
        }
        $this->assertSame($snapshot, $campaign->fresh()->getAttributes());
        $this->assertSame([], $realDisk->allFiles());
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_logging_failure_during_rollback_cleanup_does_not_mask_original_exception(): void
    {
        Storage::fake('campaign_images');
        $admin = User::factory()->admin()->create();
        $campaign = Campaign::factory()->create();
        $realDisk = Storage::disk('campaign_images');
        $mockDisk = Mockery::mock($realDisk)->makePartial();
        $mockDisk->shouldReceive('delete')->once()->andThrow(new RuntimeException('Cleanup path failure.'));
        Storage::shouldReceive('disk')->with('campaign_images')->andReturn($mockDisk);
        Log::shouldReceive('warning')->once()->andThrow(new RuntimeException('Logging failure.'));
        $this->mock(AuditLogger::class)->shouldReceive('log')->once()->andThrow(new RuntimeException('Original audit failure.'));

        try {
            app(CampaignImageService::class)->upload($admin, $campaign, $this->image(), 'Arabic', 'English', Request::create('/', 'POST'));
            $this->fail('The original audit exception must escape.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Original audit failure.', $exception->getMessage());
        }
        $this->assertNull($campaign->fresh()->image_path);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_removal_audit_failure_preserves_file_and_all_database_values(): void
    {
        Storage::fake('campaign_images');
        $admin = User::factory()->admin()->create();
        $campaign = Campaign::factory()->create(['image_alt_ar' => 'Old Arabic', 'image_alt_en' => 'Old English']);
        $path = $this->managedPath($campaign, 'jpg');
        DB::table('campaigns')->where('id', $campaign->id)->update(['image_path' => $path]);
        Storage::disk('campaign_images')->put($path, 'old image');
        $snapshot = $campaign->fresh()->getAttributes();
        $this->mock(AuditLogger::class)->shouldReceive('log')->once()->andThrow(new RuntimeException('Audit failed.'));

        try {
            app(CampaignImageService::class)->remove($admin, $campaign, Request::create('/', 'DELETE'));
            $this->fail('Audit failure must escape.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit failed.', $exception->getMessage());
        }
        $this->assertSame($snapshot, $campaign->fresh()->getAttributes());
        Storage::disk('campaign_images')->assertExists($path);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_post_commit_old_file_cleanup_failure_does_not_undo_success_or_log_sensitive_data(): void
    {
        Storage::fake('campaign_images');
        $admin = User::factory()->admin()->create();
        $campaign = Campaign::factory()->create(['image_alt_ar' => 'Old Arabic secret', 'image_alt_en' => 'Old English secret']);
        $oldPath = $this->managedPath($campaign, 'jpg');
        DB::table('campaigns')->where('id', $campaign->id)->update(['image_path' => $oldPath]);
        $realDisk = Storage::disk('campaign_images');
        $realDisk->put($oldPath, 'old image');
        $mockDisk = Mockery::mock($realDisk)->makePartial();
        $mockDisk->shouldReceive('delete')->once()->with($oldPath)->andReturn(false);
        Storage::shouldReceive('disk')->with('campaign_images')->andReturn($mockDisk);
        Log::spy();

        $updated = app(CampaignImageService::class)->upload($admin, $campaign, $this->image('new.png'), 'New Arabic secret', 'New English secret', Request::create('/', 'POST'));

        $this->assertNotSame($oldPath, $updated->image_path);
        $this->assertSame($updated->image_path, $campaign->fresh()->image_path);
        $this->assertDatabaseHas('audit_logs', ['action' => 'campaign.image_replaced', 'subject_id' => $campaign->id]);
        Log::shouldHaveReceived('warning')->once()->with('Managed Campaign image cleanup failed after a storage operation.', ['campaign_id' => $campaign->id]);
    }

    public function test_logging_failure_during_post_commit_cleanup_does_not_turn_removal_into_failure(): void
    {
        Storage::fake('campaign_images');
        $admin = User::factory()->admin()->create();
        $campaign = Campaign::factory()->create(['image_alt_ar' => 'Old Arabic', 'image_alt_en' => 'Old English']);
        $path = $this->managedPath($campaign, 'jpg');
        DB::table('campaigns')->where('id', $campaign->id)->update(['image_path' => $path]);
        $realDisk = Storage::disk('campaign_images');
        $realDisk->put($path, 'old image');
        $mockDisk = Mockery::mock($realDisk)->makePartial();
        $mockDisk->shouldReceive('delete')->once()->with($path)->andReturn(false);
        Storage::shouldReceive('disk')->with('campaign_images')->andReturn($mockDisk);
        Log::shouldReceive('warning')->once()->andThrow(new RuntimeException('Logging failed.'));

        $updated = app(CampaignImageService::class)->remove($admin, $campaign, Request::create('/', 'DELETE'));

        $this->assertNull($updated->image_path);
        $this->assertNull($campaign->fresh()->image_path);
        $this->assertDatabaseHas('audit_logs', ['action' => 'campaign.image_removed', 'subject_id' => $campaign->id]);
    }

    public function test_successful_replacement_deletes_old_file_only_after_commit(): void
    {
        Storage::fake('campaign_images');
        $admin = User::factory()->admin()->create();
        $campaign = Campaign::factory()->create(['image_alt_ar' => 'Old Arabic', 'image_alt_en' => 'Old English']);
        $oldPath = $this->managedPath($campaign, 'jpg');
        DB::table('campaigns')->where('id', $campaign->id)->update(['image_path' => $oldPath]);
        $realDisk = Storage::disk('campaign_images');
        $realDisk->put($oldPath, 'old bytes');
        $newPathAtCleanup = null;
        $mockDisk = Mockery::mock($realDisk)->makePartial();
        $mockDisk->shouldReceive('delete')->once()->with($oldPath)->andReturnUsing(function (string $path) use ($campaign, $realDisk, &$newPathAtCleanup): bool {
            $this->assertSame(0, DB::transactionLevel());
            $committed = Campaign::findOrFail($campaign->id);
            $newPathAtCleanup = $committed->image_path;
            $this->assertNotSame($path, $newPathAtCleanup);
            $this->assertSame('New Arabic', $committed->image_alt_ar);
            $this->assertSame('New English', $committed->image_alt_en);
            $this->assertDatabaseHas('audit_logs', ['action' => 'campaign.image_replaced', 'subject_id' => $campaign->id]);
            $this->assertTrue($realDisk->exists($newPathAtCleanup));

            return $realDisk->delete($path);
        });
        Storage::shouldReceive('disk')->with('campaign_images')->andReturn($mockDisk);

        $updated = app(CampaignImageService::class)->upload($admin, $campaign, $this->image('replacement.png'), 'New Arabic', 'New English', Request::create('/', 'POST'));

        $this->assertSame($newPathAtCleanup, $updated->image_path);
        $this->assertFalse($realDisk->exists($oldPath));
        $this->assertTrue($realDisk->exists($updated->image_path));
    }

    public function test_successful_removal_deletes_old_file_only_after_commit(): void
    {
        Storage::fake('campaign_images');
        $admin = User::factory()->admin()->create();
        $campaign = Campaign::factory()->create(['image_alt_ar' => 'Old Arabic', 'image_alt_en' => 'Old English']);
        $oldPath = $this->managedPath($campaign, 'webp');
        DB::table('campaigns')->where('id', $campaign->id)->update(['image_path' => $oldPath]);
        $realDisk = Storage::disk('campaign_images');
        $realDisk->put($oldPath, 'old bytes');
        $mockDisk = Mockery::mock($realDisk)->makePartial();
        $mockDisk->shouldReceive('delete')->once()->with($oldPath)->andReturnUsing(function (string $path) use ($campaign, $realDisk): bool {
            $this->assertSame(0, DB::transactionLevel());
            $committed = Campaign::findOrFail($campaign->id);
            $this->assertNull($committed->image_path);
            $this->assertNull($committed->image_alt_ar);
            $this->assertNull($committed->image_alt_en);
            $this->assertDatabaseHas('audit_logs', ['action' => 'campaign.image_removed', 'subject_id' => $campaign->id]);

            return $realDisk->delete($path);
        });
        Storage::shouldReceive('disk')->with('campaign_images')->andReturn($mockDisk);

        $updated = app(CampaignImageService::class)->remove($admin, $campaign, Request::create('/', 'DELETE'));

        $this->assertNull($updated->image_path);
        $this->assertFalse($realDisk->exists($oldPath));
    }

    public function test_removal_handles_missing_and_unmanaged_paths_and_noop_preserves_timestamp(): void
    {
        Storage::fake('campaign_images');
        try {
            $this->travelTo('2026-08-30 10:00:00');
            $originalUpdater = User::factory()->admin()->create();
            $campaign = Campaign::factory()->create(['updated_by' => $originalUpdater->id]);
            $snapshot = $campaign->fresh()->getAttributes();
            $this->travelTo('2026-08-31 10:00:00');
            $admin = User::factory()->admin()->create();
            $this->actingAs($admin)->delete(route('admin.campaigns.image.destroy', $campaign))->assertSessionHas('status', 'campaign-image-unchanged');
            $this->assertSame($snapshot, $campaign->fresh()->getAttributes());
            $this->assertSame($originalUpdater->id, $campaign->fresh()->updated_by);
            $this->assertDatabaseCount('audit_logs', 0);
        } finally {
            $this->travelBack();
        }

        foreach (['../outside.jpg', '/absolute.jpg', 'https://example.test/a.jpg', 'campaigns/'.$campaign->id.'/extra/'.Str::uuid().'.jpg', 'campaigns/'.($campaign->id + 1).'/'.Str::uuid().'.jpg'] as $unsafe) {
            DB::table('campaigns')->where('id', $campaign->id)->update(['image_path' => $unsafe, 'image_alt_ar' => 'old', 'image_alt_en' => 'old']);
            Storage::disk('campaign_images')->put('outside.jpg', 'keep');
            $this->actingAs($admin)->delete(route('admin.campaigns.image.destroy', $campaign))->assertSessionHas('status', 'campaign-image-removed');
            $this->assertNull($campaign->fresh()->image_path);
            Storage::disk('campaign_images')->assertExists('outside.jpg');
        }

        $missing = $this->managedPath($campaign, 'webp');
        DB::table('campaigns')->where('id', $campaign->id)->update(['image_path' => $missing, 'image_alt_ar' => 'old', 'image_alt_en' => 'old']);
        $this->actingAs($admin)->delete(route('admin.campaigns.image.destroy', $campaign))->assertSessionHas('status', 'campaign-image-removed');
        $this->assertNull($campaign->fresh()->image_path);
    }

    public function test_preview_is_authorized_private_and_returns_not_found_for_absent_missing_or_unmanaged_files(): void
    {
        Storage::fake('campaign_images');
        $admin = User::factory()->admin()->create();
        $campaign = Campaign::factory()->create();
        $this->actingAs($admin)->get(route('admin.campaigns.image.show', $campaign))->assertNotFound();

        foreach (['../secret.jpg', 'campaigns/'.($campaign->id + 1).'/'.Str::uuid().'.jpg'] as $unsafe) {
            DB::table('campaigns')->where('id', $campaign->id)->update(['image_path' => $unsafe]);
            $this->get(route('admin.campaigns.image.show', $campaign))->assertNotFound();
        }
        $missing = $this->managedPath($campaign, 'png');
        DB::table('campaigns')->where('id', $campaign->id)->update(['image_path' => $missing]);
        $this->get(route('admin.campaigns.image.show', $campaign))->assertNotFound();
        $this->assertStringNotContainsString('/storage/', route('admin.campaigns.image.show', $campaign));
    }

    public function test_foreign_and_traversal_paths_are_rejected_before_storage_read_or_delete(): void
    {
        Storage::fake('campaign_images');
        $admin = User::factory()->admin()->create();
        $owner = Campaign::factory()->create();
        $target = Campaign::factory()->create(['image_alt_ar' => 'Target Arabic', 'image_alt_en' => 'Target English']);
        $foreignPath = $this->managedPath($owner, 'jpg');
        $traversalPath = '../outside.jpg';
        $realDisk = Storage::disk('campaign_images');
        $realDisk->put($foreignPath, 'foreign owned bytes');
        $realDisk->put('outside.jpg', 'outside bytes');
        $mockDisk = Mockery::mock($realDisk)->makePartial();
        foreach ([$foreignPath, $traversalPath] as $forbiddenPath) {
            $mockDisk->shouldNotReceive('exists')->with($forbiddenPath);
            $mockDisk->shouldNotReceive('get')->with($forbiddenPath);
            $mockDisk->shouldNotReceive('delete')->with($forbiddenPath);
        }
        Storage::shouldReceive('disk')->with('campaign_images')->andReturn($mockDisk);

        foreach ([$foreignPath, $traversalPath] as $unsafePath) {
            DB::table('campaigns')->where('id', $target->id)->update([
                'image_path' => $unsafePath,
                'image_alt_ar' => 'Target Arabic',
                'image_alt_en' => 'Target English',
            ]);
            $this->actingAs($admin)->get(route('admin.campaigns.image.show', $target))->assertNotFound();
            app(CampaignImageService::class)->remove($admin, $target, Request::create('/', 'DELETE'));
            $this->assertNull($target->fresh()->image_path);
            $this->assertSame('foreign owned bytes', $realDisk->get($foreignPath));
            $this->assertSame('outside bytes', $realDisk->get('outside.jpg'));
        }
    }

    public function test_audits_have_exact_safe_boolean_state_and_correct_actions(): void
    {
        Storage::fake('campaign_images');
        $admin = User::factory()->admin()->create();
        $campaign = Campaign::factory()->create(['story_en' => 'public-story-secret']);
        $this->actingAs($admin)->post(route('admin.campaigns.image.store', $campaign), $this->uploadPayload('first-secret.png'));
        $this->post(route('admin.campaigns.image.store', $campaign), $this->uploadPayload('second-secret.jpg', ['image_alt_en' => 'alt-secret']));
        $this->delete(route('admin.campaigns.image.destroy', $campaign));

        $audits = AuditLog::query()->orderBy('id')->get();
        $this->assertSame(['campaign.image_uploaded', 'campaign.image_replaced', 'campaign.image_removed'], $audits->pluck('action')->all());
        $this->assertSame(['had_image' => false, 'has_image' => false], $audits[0]->old_values);
        $this->assertSame(['had_image' => false, 'has_image' => true], $audits[0]->new_values);
        $this->assertSame(['had_image' => true, 'has_image' => true], $audits[1]->old_values);
        $this->assertSame(['had_image' => true, 'has_image' => true], $audits[1]->new_values);
        $this->assertSame(['had_image' => true, 'has_image' => true], $audits[2]->old_values);
        $this->assertSame(['had_image' => true, 'has_image' => false], $audits[2]->new_values);
        foreach ($audits as $audit) {
            $this->assertSame($admin->id, $audit->actor_id);
            $this->assertSame($campaign->id, $audit->subject_id);
            $this->assertSame($campaign->getMorphClass(), $audit->subject_type);
            $this->assertSame(['had_image', 'has_image'], array_keys($audit->old_values));
            $this->assertSame(['had_image', 'has_image'], array_keys($audit->new_values));
            $encoded = json_encode($audit->getAttributes());
            $this->assertStringNotContainsString('secret', $encoded);
            $this->assertStringNotContainsString('campaigns/', $encoded);
        }
    }

    public function test_edit_ui_is_bilingual_accessible_separate_and_never_exposes_the_path(): void
    {
        Storage::fake('campaign_images');
        $admin = User::factory()->admin()->create();
        $campaign = Campaign::factory()->create(['image_alt_en' => '<script>unsafe</script>']);
        $path = $this->managedPath($campaign, 'jpg');
        DB::table('campaigns')->where('id', $campaign->id)->update(['image_path' => $path]);

        $this->actingAs($admin)->withSession(['_old_input' => ['image_alt_ar' => ['bad'], 'image_alt_en' => '<b>old</b>']])
            ->get(route('admin.campaigns.edit', $campaign))->assertOk()
            ->assertSee('enctype="multipart/form-data"', false)
            ->assertSee('lang="ar" dir="rtl"', false)->assertSee('lang="en" dir="ltr"', false)
            ->assertSee(route('admin.campaigns.image.show', $campaign))
            ->assertSee(route('admin.campaigns.image.destroy', $campaign))
            ->assertSee('Do not upload identity documents or confidential evidence.')
            ->assertDontSee($path)->assertDontSee('<script>unsafe</script>', false)->assertDontSee('<b>old</b>', false)
            ->assertDontSee('value="Array"', false)->assertDontSee('name="image_path"', false);

        $this->assertSame('{locale}/cases/{id}', app('router')->getRoutes()->getByName('cases.show')->uri());
    }

    /** @param array<string,mixed> $overrides */
    private function uploadPayload(string $name = 'campaign.png', array $overrides = [], ?UploadedFile $file = null): array
    {
        return array_merge(['image' => $file ?? $this->image($name), 'image_alt_ar' => 'Arabic alternative', 'image_alt_en' => 'English alternative'], $overrides);
    }

    private function image(string $name = 'campaign.jpg'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 40, 40);
    }

    private function managedPath(Campaign $campaign, string $extension): string
    {
        return 'campaigns/'.$campaign->id.'/'.Str::uuid().'.'.$extension;
    }
}
