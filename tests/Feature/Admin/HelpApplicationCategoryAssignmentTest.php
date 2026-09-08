<?php

namespace Tests\Feature\Admin;

use App\Enums\HelpApplicationStatus;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\HelpApplication;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class HelpApplicationCategoryAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private const UNAVAILABLE = 'The selected category is unavailable. / الفئة المحددة غير متاحة.';

    public function test_route_is_exact_uuid_rate_limited_post_and_only_assignment_mutation(): void
    {
        $routes = collect(app('router')->getRoutes());
        $route = $routes->firstWhere('action.as', 'admin.help-applications.in-review.assign-category');
        $this->assertSame(['POST'], $route->methods());
        $this->assertSame('admin/help-applications/in-review/{helpApplication}/assign-category', $route->uri());
        $this->assertSame(['web', 'auth', 'role:admin,super_admin', 'throttle:10,1'], $route->gatherMiddleware());
        $this->assertSame('[\\da-fA-F]{8}-[\\da-fA-F]{4}-[\\da-fA-F]{4}-[\\da-fA-F]{4}-[\\da-fA-F]{12}', $route->wheres['helpApplication']);
        $mutations = $routes->filter(fn ($candidate) => str_starts_with($candidate->uri(), 'admin/help-applications/in-review')
            && array_intersect($candidate->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']));
        $this->assertCount(1, $mutations);
        $this->assertSame($route, $mutations->first());
        $this->assertLessThan(
            $routes->search(fn ($candidate) => $candidate->getName() === 'admin.help-applications.in-review.show'),
            $routes->search(fn ($candidate) => $candidate->getName() === $route->getName()),
        );
    }

    public function test_guest_applicant_disabled_and_password_change_accounts_are_blocked(): void
    {
        $application = HelpApplication::factory()->underReview()->create();
        $url = route('admin.help-applications.in-review.assign-category', $application->reference);
        $this->post($url, ['category' => 'medical'])->assertRedirect(route('login'));
        $this->actingAs(User::factory()->user()->create())->post($url, ['category' => 'medical'])->assertForbidden();
        $this->actingAs(User::factory()->admin()->disabled()->create())->post($url, ['category' => 'medical'])->assertRedirect(route('login'));
        $this->actingAs(User::factory()->admin()->mustChangePassword()->create())->post($url, ['category' => 'medical'])
            ->assertRedirect(route('password.change.required.edit'));
    }

    public function test_assigned_admin_can_assign_but_foreign_and_orphaned_applications_are_concealed(): void
    {
        $admin = User::factory()->admin()->create();
        $other = User::factory()->admin()->create();
        $category = Category::factory()->create(['slug' => 'medical']);
        $mine = $this->reviewingApplication($admin);
        $foreign = $this->reviewingApplication($other);
        $orphan = $this->orphanedApplication();

        $this->actingAs($admin)->post($this->assignUrl($mine), ['category' => 'medical'])->assertRedirect($this->showUrl($mine));
        $this->post($this->assignUrl($foreign), ['category' => 'medical'])->assertNotFound();
        $this->post($this->assignUrl($orphan), ['category' => 'medical'])->assertNotFound();
        $this->assertSame($category->id, $mine->fresh()->category_id);
        $this->assertNull($foreign->fresh()->category_id);
        $this->assertNull($orphan->fresh()->category_id);
    }

    public function test_super_admin_can_assign_assigned_and_orphaned_under_review_applications(): void
    {
        $reviewer = User::factory()->admin()->create();
        $super = User::factory()->superAdmin()->create();
        Category::factory()->create(['slug' => 'medical']);
        foreach ([$this->reviewingApplication($reviewer), $this->orphanedApplication()] as $application) {
            $this->actingAs($super)->post($this->assignUrl($application), ['category' => 'medical'])
                ->assertRedirect($this->showUrl($application));
            $this->assertSame($super->id, $application->fresh()->category_assigned_by);
        }
    }

    public function test_every_other_status_and_invalid_reference_is_concealed_without_mutation(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $admin = User::factory()->admin()->create();
        Category::factory()->create(['slug' => 'medical']);
        foreach (HelpApplicationStatus::cases() as $status) {
            if ($status === HelpApplicationStatus::UnderReview) {
                continue;
            }
            $application = HelpApplication::factory()->create(['status' => $status, 'reviewed_by' => $admin->id]);
            $this->actingAs($admin)->post($this->assignUrl($application), ['category' => 'medical'])->assertNotFound();
            $this->assertNull($application->fresh()->category_id);
        }
        foreach (['123', 'malformed', (string) Str::uuid()] as $reference) {
            $this->post('/admin/help-applications/in-review/'.$reference.'/assign-category', ['category' => 'medical'])->assertNotFound();
        }
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_invalid_category_shapes_return_one_exact_generic_field_error(): void
    {
        $admin = User::factory()->admin()->create();
        foreach ([null, ['array'], str_repeat('a', 161), 'Not Canonical', '--bad--'] as $input) {
            $application = $this->reviewingApplication($admin);
            $response = $this->actingAs($admin)->from($this->showUrl($application))
                ->post($this->assignUrl($application), $input === null ? [] : ['category' => $input]);
            $response->assertRedirect($this->showUrl($application))->assertSessionHasErrors(['category' => self::UNAVAILABLE]);
            $this->assertNull($application->fresh()->category_id);
        }
    }

    public function test_missing_inactive_deleted_nonexistent_and_noncanonical_categories_are_unavailable(): void
    {
        $admin = User::factory()->admin()->create();
        Category::factory()->inactive()->create(['slug' => 'inactive']);
        Category::factory()->trashed()->create(['slug' => 'deleted']);
        foreach (['missing', 'inactive', 'deleted'] as $slug) {
            $application = $this->reviewingApplication($admin);
            $this->actingAs($admin)->from($this->showUrl($application))->post($this->assignUrl($application), ['category' => $slug])
                ->assertSessionHasErrors(['category' => self::UNAVAILABLE]);
            $this->assertNull($application->fresh()->category_id);
        }
    }

    public function test_success_changes_only_five_approved_fields_with_one_shared_timestamp(): void
    {
        $reviewer = User::factory()->admin()->create();
        $admin = User::factory()->superAdmin()->create();
        $application = $this->reviewingApplication($reviewer);
        DB::table('help_applications')->where('id', $application->id)->update(['updated_at' => now()->subDay()]);
        $category = Category::factory()->create(['slug' => 'medical']);
        $before = (array) DB::table('help_applications')->where('id', $application->id)->first();

        $this->actingAs($admin)->post($this->assignUrl($application), [
            'category' => 'medical', 'category_id' => 999999, 'updated_by' => 999999,
            'reviewed_by' => 999999, 'status' => 'approved', 'updated_at' => '2000-01-01 00:00:00',
            'identity_document_number' => 'ATTACKER SECRET',
        ])->assertRedirect($this->showUrl($application))->assertSessionHas('status', 'help-application-category-assigned')
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Pragma', 'no-cache');

        $after = (array) DB::table('help_applications')->where('id', $application->id)->first();
        $changed = array_keys(array_filter($after, fn ($value, $key) => $value !== $before[$key], ARRAY_FILTER_USE_BOTH));
        $this->assertSame(['category_id', 'category_assigned_by', 'category_assigned_at', 'updated_by', 'updated_at'], $changed);
        $this->assertSame($category->id, $after['category_id']);
        $this->assertSame($admin->id, $after['category_assigned_by']);
        $this->assertSame($admin->id, $after['updated_by']);
        $this->assertSame($after['category_assigned_at'], $after['updated_at']);
        $this->assertSame('under_review', $after['status']);
        $this->assertTrue((bool) $after['open_slot']);
    }

    public function test_partial_assignment_states_fail_closed_independently(): void
    {
        $admin = User::factory()->admin()->create();
        $category = Category::factory()->create(['slug' => 'medical']);
        $states = [
            ['category_id' => $category->id],
            ['category_assigned_at' => now()],
            ['category_assigned_by' => $admin->id],
            ['category_id' => $category->id, 'category_assigned_by' => $admin->id],
            ['category_assigned_by' => $admin->id, 'category_assigned_at' => now()],
        ];
        foreach ($states as $state) {
            $application = $this->reviewingApplication($admin, $state);
            $before = (array) DB::table('help_applications')->where('id', $application->id)->first();
            $this->actingAs($admin)->post($this->assignUrl($application), ['category' => 'medical'])->assertNotFound();
            $this->assertSame($before, (array) DB::table('help_applications')->where('id', $application->id)->first());
        }
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_corrupt_lifecycle_invariants_fail_closed_without_side_effects(): void
    {
        $admin = User::factory()->admin()->create();
        Category::factory()->create(['slug' => 'medical']);
        $states = [
            ['open_slot' => null],
            ['review_started_at' => null],
            ['status_changed_at' => null],
            ['review_started_at' => now()->subHours(2)],
            ['submitted_at' => null],
            ['decided_by' => $admin->id],
            ['decided_at' => now()],
            ['appeal_eligibility_ended_at' => now()],
            ['updated_by' => null],
        ];
        foreach ($states as $state) {
            $application = $this->reviewingApplication($admin, $state);
            $before = (array) DB::table('help_applications')->where('id', $application->id)->first();
            $this->actingAs($admin)->post($this->assignUrl($application), ['category' => 'medical'])->assertNotFound();
            $this->assertSame($before, (array) DB::table('help_applications')->where('id', $application->id)->first());
        }
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_same_and_different_category_repetitions_are_identical_no_ops_before_category_lookup(): void
    {
        $admin = User::factory()->admin()->create();
        $application = $this->reviewingApplication($admin);
        $category = Category::factory()->create(['slug' => 'first']);
        $inactive = Category::factory()->inactive()->create(['slug' => 'inactive']);
        $deleted = Category::factory()->trashed()->create(['slug' => 'deleted']);
        $this->actingAs($admin)->post($this->assignUrl($application), ['category' => 'first'])->assertSessionHas('status', 'help-application-category-assigned');
        $assigned = (array) DB::table('help_applications')->where('id', $application->id)->first();

        foreach (['first', 'different-missing', $inactive->slug, $deleted->slug] as $slug) {
            $this->post($this->assignUrl($application), ['category' => $slug])
                ->assertRedirect($this->showUrl($application))->assertSessionHas('status', 'help-application-category-already-assigned');
            $this->assertSame($assigned, (array) DB::table('help_applications')->where('id', $application->id)->first());
        }
        $this->assertSame($category->id, $application->fresh()->category_id);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_exact_privacy_safe_audit_is_created_once_with_correct_relations(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'AUDIT ACTOR PRIVATE NAME']);
        $application = $this->reviewingApplication($admin, ['private_story' => 'AUDIT PRIVATE STORY']);
        Category::factory()->create(['slug' => 'medical', 'name_en' => 'AUDIT PRIVATE CATEGORY NAME']);

        $this->actingAs($admin)->post($this->assignUrl($application), ['category' => 'medical']);

        $audit = AuditLog::query()->sole();
        $this->assertSame('help_application.category_assigned', $audit->action);
        $this->assertSame(['status' => 'under_review', 'open_slot' => true, 'category_slug' => null], $audit->old_values);
        $this->assertSame(['status' => 'under_review', 'open_slot' => true, 'category_slug' => 'medical'], $audit->new_values);
        $this->assertTrue($audit->actor->is($admin));
        $this->assertTrue($audit->subject->is($application));
        $encoded = json_encode([$audit->old_values, $audit->new_values]);
        foreach (['AUDIT ACTOR PRIVATE NAME', 'AUDIT PRIVATE STORY', 'AUDIT PRIVATE CATEGORY NAME', (string) $application->id, (string) $admin->id] as $private) {
            if (strlen($private) > 1) {
                $this->assertStringNotContainsString($private, $encoded);
            }
        }
    }

    public function test_audit_failure_rolls_back_the_complete_application_row(): void
    {
        $this->withoutExceptionHandling();
        $admin = User::factory()->admin()->create();
        $application = $this->reviewingApplication($admin);
        Category::factory()->create(['slug' => 'medical']);
        $before = (array) DB::table('help_applications')->where('id', $application->id)->first();
        $this->mock(AuditLogger::class, function (MockInterface $mock): void {
            $mock->shouldReceive('log')->once()->andThrow(new RuntimeException('synthetic audit failure'));
        });

        try {
            $this->actingAs($admin)->post($this->assignUrl($application), ['category' => 'medical']);
            $this->fail('Expected audit failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('synthetic audit failure', $exception->getMessage());
        }

        $this->assertSame($before, (array) DB::table('help_applications')->where('id', $application->id)->first());
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_unassigned_interface_has_one_exact_slug_form_and_ordered_active_options(): void
    {
        $admin = User::factory()->admin()->create();
        $application = $this->reviewingApplication($admin);
        $second = Category::factory()->atPosition(2)->create(['slug' => 'second', 'name_en' => 'Second', 'name_ar' => 'الثانية']);
        $firstB = Category::factory()->atPosition(1)->create(['slug' => 'first-b', 'name_en' => 'Beta', 'name_ar' => 'بيتا']);
        $firstA = Category::factory()->atPosition(1)->create(['slug' => 'first-a', 'name_en' => 'Alpha', 'name_ar' => 'ألفا']);
        $inactive = Category::factory()->inactive()->create(['slug' => 'inactive']);
        $deleted = Category::factory()->trashed()->create(['slug' => 'deleted']);

        $response = $this->actingAs($admin)->get($this->showUrl($application));
        $html = $response->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, '<form class="mt-4"'));
        $this->assertStringContainsString('method="POST" action="'.$this->assignUrl($application).'"', $html);
        $this->assertSame(1, substr_count($html, 'name="category"'));
        preg_match('/<form class="mt-4".*?<\/form>/s', $html, $form);
        $this->assertSame(1, substr_count($form[0], 'name="_token"'));
        $this->assertMatchesRegularExpression('/<select\b[^>]*\bname="category"[^>]*\brequired\b[^>]*>\s*<option value="">Select \/ اختر<\/option>/', $form[0]);
        $this->assertDoesNotMatchRegularExpression('/<option value="(?:first-a|first-b|second)"[^>]*\bselected\b/', $form[0]);
        $this->assertStringNotContainsString('aria-invalid', $form[0]);
        $this->assertStringNotContainsString('aria-describedby="category-error"', $form[0]);
        $this->assertStringNotContainsString('id="category-error"', $form[0]);
        $this->assertStringContainsString('border-gray-300 focus:border-indigo-500 focus:ring-indigo-500', $form[0]);
        $this->assertStringNotContainsString('border-red-200', $form[0]);
        $response->assertSeeInOrder([$firstA->slug, $firstB->slug, $second->slug])
            ->assertSee('Assign category /')->assertDontSee($inactive->slug)->assertDontSee($deleted->slug);
        foreach ([(string) $firstA->id, (string) $admin->id, (string) $application->id] as $numericId) {
            $this->assertStringNotContainsString('value="'.$numericId.'"', $html);
        }
    }

    public function test_no_active_categories_shows_exact_empty_state_without_assignment_form(): void
    {
        $admin = User::factory()->admin()->create();
        $application = $this->reviewingApplication($admin);
        Category::factory()->inactive()->create();
        Category::factory()->trashed()->create();
        $response = $this->actingAs($admin)->get($this->showUrl($application));
        $response->assertSee('No active categories are available. /')->assertSee('لا توجد فئات نشطة متاحة.')
            ->assertDontSee($this->assignUrl($application));
    }

    public function test_validation_flashes_only_safe_category_slug_with_exact_aria_error(): void
    {
        $admin = User::factory()->admin()->create();
        $application = $this->reviewingApplication($admin);
        Category::factory()->create(['slug' => 'available-fallback']);
        $response = $this->actingAs($admin)->from($this->showUrl($application))->post($this->assignUrl($application), [
            'category' => 'missing-safe-slug', 'identity_document_number' => 'DO-NOT-FLASH',
            'category_id' => 999, 'reviewed_by' => 999,
        ]);
        $response->assertRedirect($this->showUrl($application))->assertSessionHasErrors(['category' => self::UNAVAILABLE])
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Pragma', 'no-cache');
        $this->assertSame(['category' => 'missing-safe-slug'], session()->getOldInput());
        $errorPage = $this->get($this->showUrl($application))->assertSee(self::UNAVAILABLE)
            ->assertSee('aria-invalid="true"', false)->assertSee('aria-describedby="category-error"', false)
            ->assertSee('border-red-200 focus:border-red-500 focus:ring-red-500', false)
            ->assertSee('id="category-error" class="mt-2 text-sm text-red-800"', false)
            ->assertDontSee('DO-NOT-FLASH');
        $this->assertSame(1, substr_count($errorPage->getContent(), 'aria-describedby="category-error"'));
        $this->assertSame(1, substr_count($errorPage->getContent(), 'id="category-error"'));
    }

    public function test_missing_category_uses_safe_http_validation_and_exact_private_headers(): void
    {
        $admin = User::factory()->admin()->create();
        $application = $this->reviewingApplication($admin);
        Category::factory()->create(['slug' => 'available']);

        $response = $this->actingAs($admin)->from($this->showUrl($application))->post($this->assignUrl($application), [
            'identity_document_number' => 'DO-NOT-FLASH',
        ]);

        $response->assertRedirect($this->showUrl($application))->assertSessionHasErrors(['category' => self::UNAVAILABLE])
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Pragma', 'no-cache');
        $this->assertSame([], session()->getOldInput());
    }

    public function test_safe_valid_old_slug_is_selected_only_after_validation_redirect(): void
    {
        $admin = User::factory()->admin()->create();
        $application = $this->reviewingApplication($admin);
        Category::factory()->create(['slug' => 'available']);
        $slug = 'later-available';

        $cleanHtml = $this->actingAs($admin)->get($this->showUrl($application))->assertOk()->getContent();
        $this->assertStringNotContainsString('value="'.$slug.'" selected', $cleanHtml);

        $this->from($this->showUrl($application))->post($this->assignUrl($application), ['category' => $slug])
            ->assertRedirect($this->showUrl($application))->assertSessionHasErrors(['category' => self::UNAVAILABLE]);
        Category::factory()->create(['slug' => $slug]);

        $redirectedHtml = $this->get($this->showUrl($application))->assertOk()->getContent();
        $this->assertStringContainsString('value="'.$slug.'" selected', $redirectedHtml);
    }

    public function test_success_and_repeat_show_exact_bilingual_messages_and_hide_form_after_assignment(): void
    {
        $admin = User::factory()->admin()->create();
        $application = $this->reviewingApplication($admin);
        Category::factory()->create(['slug' => 'medical', 'name_en' => 'Medical Assistance', 'name_ar' => 'المساعدة الطبية']);

        $this->actingAs($admin)->post($this->assignUrl($application), ['category' => 'medical']);
        $this->get($this->showUrl($application))->assertSee('Category assigned successfully. /')
            ->assertSee('تم تعيين الفئة بنجاح.')->assertSee('Medical Assistance /')->assertSee('المساعدة الطبية')
            ->assertDontSee($this->assignUrl($application));
        $this->post($this->assignUrl($application), ['category' => 'medical']);
        $this->get($this->showUrl($application))->assertSee('Category already assigned. /')
            ->assertSee('تم تعيين الفئة بالفعل.')->assertDontSee($this->assignUrl($application));
    }

    public function test_inactive_and_deleted_assigned_categories_remain_historically_visible_and_unreplaceable(): void
    {
        $admin = User::factory()->admin()->create();
        foreach (['inactive', 'deleted'] as $state) {
            $application = $this->reviewingApplication($admin);
            $category = Category::factory()->create([
                'slug' => 'historical-'.$state, 'name_en' => 'Historical '.$state, 'name_ar' => 'فئة تاريخية',
            ]);
            $this->actingAs($admin)->post($this->assignUrl($application), ['category' => $category->slug]);
            $state === 'inactive' ? $category->forceFill(['is_active' => false])->save() : $category->delete();

            $this->get($this->showUrl($application))->assertSee('Historical '.$state)->assertSee('فئة تاريخية')
                ->assertSee('Unavailable for new assignments /')->assertSee('غير متاحة للتعيينات الجديدة')
                ->assertDontSee($this->assignUrl($application));
        }
    }

    public function test_viewing_category_interface_is_read_only_and_uses_no_document_storage(): void
    {
        Storage::shouldReceive('disk')->never();
        Storage::shouldReceive('exists')->never();
        Storage::shouldReceive('get')->never();
        Storage::shouldReceive('readStream')->never();
        Storage::shouldReceive('url')->never();
        Storage::shouldReceive('temporaryUrl')->never();
        $admin = User::factory()->admin()->create();
        $application = $this->reviewingApplication($admin);
        Category::factory()->create();
        $before = (array) DB::table('help_applications')->where('id', $application->id)->first();

        $this->actingAs($admin)->get($this->showUrl($application))->assertOk();

        $this->assertSame($before, (array) DB::table('help_applications')->where('id', $application->id)->first());
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_deterministic_stale_detail_read_exposes_no_category_options_or_actionable_form(): void
    {
        $admin = User::factory()->admin()->create();
        $application = $this->reviewingApplication($admin);
        Category::factory()->create(['slug' => 'stale-private-category', 'name_en' => 'STALE CATEGORY OPTION']);
        $changed = false;
        DB::listen(function ($query) use ($application, &$changed): void {
            if (! $changed && str_contains($query->sql, 'from "help_applications"') && str_contains($query->sql, '"full_name"')) {
                $changed = true;
                DB::table('help_applications')->where('id', $application->id)->update(['status' => 'pending']);
            }
        });

        $response = $this->actingAs($admin)->get($this->showUrl($application));

        $response->assertOk()->assertDontSee('STALE CATEGORY OPTION')->assertDontSee($this->assignUrl($application));
        $this->assertTrue($changed);
    }

    public function test_applicant_interface_never_exposes_category_assignment(): void
    {
        $admin = User::factory()->admin()->create();
        $application = $this->reviewingApplication($admin);
        Category::factory()->create(['slug' => 'medical']);
        $this->actingAs($application->applicant)->get(route('help-applications.index'))
            ->assertOk()->assertSee('Under review')->assertDontSee('Assign category')->assertDontSee('medical');
    }

    public function test_success_has_no_side_effect_beyond_one_allowlisted_audit(): void
    {
        Notification::fake();
        Mail::fake();
        Queue::fake();
        Bus::fake();
        Storage::shouldReceive('disk')->never();
        Storage::shouldReceive('exists')->never();
        Storage::shouldReceive('get')->never();
        Storage::shouldReceive('readStream')->never();
        Storage::shouldReceive('url')->never();
        Storage::shouldReceive('temporaryUrl')->never();
        $admin = User::factory()->admin()->create();
        $application = $this->reviewingApplication($admin);
        Category::factory()->create(['slug' => 'medical']);
        $notificationCounts = collect([
            'internal_notification_events', 'internal_notification_event_recipients', 'internal_notifications',
        ])->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()]);

        $this->actingAs($admin)->post($this->assignUrl($application), ['category' => 'medical']);

        $this->assertDatabaseCount('audit_logs', 1);
        foreach ($notificationCounts as $table => $count) {
            $this->assertSame($count, DB::table($table)->count());
        }
        Notification::assertNothingSent();
        Mail::assertNothingSent();
        Queue::assertNothingPushed();
        Bus::assertNothingDispatched();
    }

    private function reviewingApplication(User $reviewer, array $attributes = []): HelpApplication
    {
        $timestamp = now()->subMinute();

        return HelpApplication::factory()->underReview()->create(array_merge([
            'reviewed_by' => $reviewer->id,
            'updated_by' => $reviewer->id,
            'review_started_at' => $timestamp,
            'status_changed_at' => $timestamp,
        ], $attributes));
    }

    private function orphanedApplication(array $attributes = []): HelpApplication
    {
        $timestamp = now()->subMinute();

        return HelpApplication::factory()->underReview()->create(array_merge([
            'reviewed_by' => null,
            'updated_by' => null,
            'review_started_at' => $timestamp,
            'status_changed_at' => $timestamp,
        ], $attributes));
    }

    private function assignUrl(HelpApplication $application): string
    {
        return route('admin.help-applications.in-review.assign-category', $application->reference);
    }

    private function showUrl(HelpApplication $application): string
    {
        return route('admin.help-applications.in-review.show', $application->reference);
    }
}
