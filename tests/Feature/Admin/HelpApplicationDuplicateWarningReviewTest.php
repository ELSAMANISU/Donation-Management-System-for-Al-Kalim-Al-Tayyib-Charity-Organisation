<?php

namespace Tests\Feature\Admin;

use App\Enums\HelpApplicationStatus;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\HelpApplication;
use App\Models\HelpApplicationDocument;
use App\Models\HelpApplicationDuplicateWarning as Warning;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\HelpApplicationDuplicateWarningResolutionService;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class HelpApplicationDuplicateWarningReviewTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $admin = User::factory()->admin()->create();
        $app = HelpApplication::factory()->underReview()->create(['reviewed_by' => $admin->id]);
        $prior = HelpApplication::factory()->closed()->create(['full_name' => '<script>Prior sentinel</script>']);
        $warning = Warning::factory()->create(['submitted_application_id' => $app->id, 'matched_application_id' => $prior->id]);

        return [$admin, $app, $prior, $warning];
    }

    private function indexUrl(HelpApplication $app): string
    {
        return route('admin.help-applications.in-review.duplicate-warnings.index', $app->reference);
    }

    private function resolveUrl(HelpApplication $app, Warning $warning): string
    {
        return route('admin.help-applications.in-review.duplicate-warnings.resolve', [$app->reference, $warning->reference]);
    }

    private function payload(string $outcome = 'confirmed_match'): array
    {
        return ['outcome' => $outcome, 'resolution_note' => '  A deliberate  private note.  '];
    }

    public function test_exact_routes_and_only_two_warning_endpoints(): void
    {
        $routes = collect(app('router')->getRoutes())->filter(fn ($r) => str_contains($r->uri(), 'duplicate-warnings'))->values();
        $this->assertCount(2, $routes);
        foreach (['index', 'resolve'] as $i => $action) {
            $route = $routes[$i];
            $this->assertSame('admin.help-applications.in-review.duplicate-warnings.'.$action, $route->getName());
            $this->assertSame('admin/help-applications/in-review/{helpApplication}/duplicate-warnings'.($i ? '/{duplicateWarning}/resolve' : ''), $route->uri());
            $this->assertSame($i ? ['POST'] : ['GET', 'HEAD'], $route->methods());
            $this->assertSame($i ? ['web', 'auth', 'role:admin,super_admin', 'throttle:10,1'] : ['web', 'auth', 'role:admin,super_admin'], $route->gatherMiddleware());
            foreach ($route->wheres as $constraint) {
                $this->assertSame('[\\da-fA-F]{8}-[\\da-fA-F]{4}-[\\da-fA-F]{4}-[\\da-fA-F]{4}-[\\da-fA-F]{12}', $constraint);
            }
            $this->assertCount($i ? 2 : 1, $route->wheres);
        }
    }

    public function test_guest_applicant_disabled_and_password_change_boundaries(): void
    {
        [$admin, $app, $prior, $warning] = $this->fixture();
        foreach (['get', 'post'] as $method) {
            $url = $method === 'get' ? $this->indexUrl($app) : $this->resolveUrl($app, $warning);
            auth()->forgetGuards();
            $this->$method($url)->assertRedirect(route('login'));
            $this->actingAs(User::factory()->user()->create())->$method($url)->assertForbidden();
            $this->actingAs(User::factory()->admin()->disabled()->create())->$method($url)->assertRedirect(route('login'));
            $this->actingAs(User::factory()->admin()->mustChangePassword()->create())->$method($url)->assertRedirect(route('password.change.required.edit'));
        }
    }

    public function test_foreign_and_orphaned_assignments_are_concealed_but_super_admin_has_oversight(): void
    {
        [$admin, $app, $prior, $warning] = $this->fixture();
        $other = User::factory()->admin()->create();
        $this->actingAs($other)->get($this->indexUrl($app))->assertNotFound();
        $this->post($this->resolveUrl($app, $warning), $this->payload())->assertNotFound();
        $admin->delete();
        $this->get($this->indexUrl($app))->assertNotFound();
        $this->post($this->resolveUrl($app, $warning), $this->payload())->assertNotFound();
        $this->actingAs(User::factory()->superAdmin()->create())->get($this->indexUrl($app))->assertOk();
        $this->post($this->resolveUrl($app, $warning), $this->payload())->assertRedirect($this->indexUrl($app));
    }

    public function test_all_other_statuses_and_bad_references_are_concealed_even_for_invalid_payloads(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        [$admin, $app, $prior, $warning] = $this->fixture();
        $this->actingAs($admin);
        foreach (HelpApplicationStatus::cases() as $status) {
            if ($status === HelpApplicationStatus::UnderReview) {
                continue;
            }
            DB::table('help_applications')->where('id', $app->id)->update(['status' => $status->value]);
            $this->get($this->indexUrl($app))->assertNotFound();
            $this->post($this->resolveUrl($app, $warning), [])->assertNotFound();
        }
        DB::table('help_applications')->where('id', $app->id)->update(['status' => 'under_review']);
        foreach (['123', 'malformed', (string) Str::uuid()] as $ref) {
            $this->get('/admin/help-applications/in-review/'.$ref.'/duplicate-warnings')->assertNotFound();
            $this->post('/admin/help-applications/in-review/'.$ref.'/duplicate-warnings/'.$warning->reference.'/resolve')->assertNotFound();
            $this->post('/admin/help-applications/in-review/'.$app->reference.'/duplicate-warnings/'.$ref.'/resolve')->assertNotFound();
        }
    }

    public function test_directional_warning_ownership(): void
    {
        [$admin, $app, $prior, $warning] = $this->fixture();
        $foreign = Warning::factory()->create();
        $reverse = Warning::factory()->create(['submitted_application_id' => $prior->id, 'matched_application_id' => $app->id]);
        $this->actingAs($admin);
        foreach ([$foreign, $reverse] as $wrong) {
            $this->post($this->resolveUrl($app, $wrong), $this->payload())->assertNotFound();
            $this->get($this->indexUrl($app))->assertDontSee($wrong->reference);
        }
    }

    public function test_comparison_is_escaped_minimal_and_never_decrypts_identity_numbers(): void
    {
        [$admin, $app, $prior, $warning] = $this->fixture();
        DB::table('help_applications')->whereIn('id', [$app->id, $prior->id])->update(['identity_document_number' => 'corrupt-identity-ciphertext']);
        DB::enableQueryLog();
        $response = $this->actingAs($admin)->get($this->indexUrl($app))->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Pragma', 'no-cache')
            ->assertSee($app->reference)->assertSee($prior->reference)->assertSee($app->full_name)
            ->assertSee('&lt;script&gt;Prior sentinel&lt;/script&gt;', false)->assertDontSee('<script>Prior sentinel</script>', false)
            ->assertSee('Current application / الطلب الحالي')->assertSee('Possible prior application / الطلب السابق المحتمل')
            ->assertSee('A stored identity match was detected. The identity number is not displayed.');
        foreach (['corrupt-identity-ciphertext', $prior->email, $prior->phone, $prior->address, $prior->private_story, $prior->preferred_receiving_method] as $secret) {
            $response->assertDontSee($secret);
        }
        $sql = implode("\n", array_column(DB::getQueryLog(), 'query'));
        foreach (['identity_document_number', 'identity_blind_index', 'private_story', 'requested_amount', 'category_id', 'help_application_documents', 'campaigns', 'audit_logs', 'internal_notification'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $sql);
        }
        $rendered = $response->viewData('warnings')->first();
        $this->assertSame(['reference', 'status', 'created_at', 'resolved_at', 'resolution_note'], array_keys($rendered->getAttributes()));
        $this->assertSame(['reference', 'status', 'full_name', 'date_of_birth', 'identity_document_type', 'identity_issuing_country', 'submitted_at'], array_keys($rendered->current->getAttributes()));
    }

    public function test_order_pagination_counts_and_empty_state(): void
    {
        [$admin, $app, $prior, $warning] = $this->fixture();
        Warning::factory()->count(25)->create(['submitted_application_id' => $app->id, 'created_at' => now()->addMinute()]);
        $response = $this->actingAs($admin)->get($this->indexUrl($app))->assertOk();
        $this->assertCount(25, $response->viewData('warnings'));
        $this->assertSame($warning->reference, $response->viewData('warnings')->first()->reference);
        $this->assertSame(26, $response->viewData('counts')->get('unreviewed'));
        $this->assertCount(1, $this->get($this->indexUrl($app).'?page=2')->viewData('warnings'));
        $empty = HelpApplication::factory()->underReview()->create(['reviewed_by' => $admin->id]);
        $this->get($this->indexUrl($empty))->assertSee('No possible matches. / لا توجد مطابقات محتملة.')->assertDontSee('name="resolution_note"', false)->assertDontSee('All possible matches have been reviewed.');
    }

    public static function invalidInputs(): array
    {
        return [
            'missing outcome' => [null, 'A valid long note', 'outcome', 'Select a supported outcome. / اختر نتيجة مدعومة.'],
            'padded outcome' => [' dismissed ', 'A valid long note', 'outcome', 'Select a supported outcome. / اختر نتيجة مدعومة.'],
            'invalid outcome' => ['unreviewed', 'A valid long note', 'outcome', 'Select a supported outcome. / اختر نتيجة مدعومة.'],
            'array outcome' => [['dismissed'], 'A valid long note', 'outcome', 'Select a supported outcome. / اختر نتيجة مدعومة.'],
            'missing note' => ['dismissed', null, 'resolution_note', 'Enter a resolution note. / أدخل ملاحظة الحسم.'],
            'array note' => ['dismissed', ['secret'], 'resolution_note', 'The resolution note must be text. / يجب أن تكون ملاحظة الحسم نصًا.'],
            'blank note' => ['dismissed', '    ', 'resolution_note', 'Enter a resolution note. / أدخل ملاحظة الحسم.'],
            'short trimmed note' => ['dismissed', '  123456789  ', 'resolution_note', 'The resolution note must contain at least 10 characters. / يجب أن تتكون ملاحظة الحسم من 10 أحرف على الأقل.'],
            'long note' => ['dismissed', str_repeat('a', 1001), 'resolution_note', 'The resolution note must not exceed 1000 characters. / يجب ألا تتجاوز ملاحظة الحسم 1000 حرف.'],
        ];
    }

    #[DataProvider('invalidInputs')]
    public function test_validation_and_old_input_privacy(mixed $outcome, mixed $note, string $field, string $message): void
    {
        [$admin, $app, $prior, $warning] = $this->fixture();
        $response = $this->actingAs($admin)->post($this->resolveUrl($app, $warning), ['outcome' => $outcome, 'resolution_note' => $note, 'identity_document_number' => 'secret', 'resolver_id' => 999]);
        $response->assertRedirect($this->indexUrl($app))->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Pragma', 'no-cache');
        $this->assertSame($message, session('errors')->getBag('warning_'.$warning->reference)->first($field));
        $this->assertSame(in_array($outcome, ['confirmed_match', 'dismissed'], true) ? ['outcome' => $outcome] : [], session('_old_input'));
        $page = $this->get($this->indexUrl($app))->assertOk()->assertSee($message);
        $this->assertSame(1, substr_count($page->getContent(), 'aria-invalid="true"'));
        $this->assertSame(1, substr_count($page->getContent(), 'aria-describedby='));
        $page->assertSee('></textarea>', false);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public static function outcomes(): array
    {
        return [['confirmed_match'], ['dismissed']];
    }

    #[DataProvider('outcomes')]
    public function test_exact_mutation_encryption_audit_and_no_prohibited_effects(string $outcome): void
    {
        [$admin, $app, $prior, $warning] = $this->fixture();
        Warning::factory()->create(['submitted_application_id' => $app->id]);
        $tables = ['help_applications', 'help_application_documents', 'internal_notification_events', 'internal_notification_event_recipients', 'internal_notifications', 'campaigns'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->get()->toJson();
        }
        $otherWarnings = DB::table('help_application_duplicate_warnings')->where('id', '<>', $warning->id)->get()->toJson();
        $rawBefore = (array) DB::table('help_application_duplicate_warnings')->find($warning->id);
        Bus::fake();
        Mail::fake();
        Notification::fake();
        Queue::fake();
        Storage::shouldReceive('disk')->never();
        $this->actingAs($admin)->post($this->resolveUrl($app, $warning), $this->payload($outcome) + ['status' => 'rejected', 'resolved_by' => 999, 'resolution_at' => '2000-01-01'])
            ->assertRedirect($this->indexUrl($app))->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Pragma', 'no-cache')
            ->assertSessionHas('status', 'Duplicate warning resolved successfully. / تم حسم تحذير التطابق بنجاح.');
        $rawAfter = (array) DB::table('help_application_duplicate_warnings')->find($warning->id);
        $mutable = ['status', 'resolved_by', 'resolved_at', 'resolution_note', 'updated_at'];
        $this->assertSame(array_diff_key($rawBefore, array_flip($mutable)), array_diff_key($rawAfter, array_flip($mutable)));
        $this->assertSame($outcome, $rawAfter['status']);
        $this->assertSame($admin->id, $rawAfter['resolved_by']);
        $this->assertSame($rawAfter['resolved_at'], $rawAfter['updated_at']);
        $this->assertNotSame(trim($this->payload()['resolution_note']), $rawAfter['resolution_note']);
        $this->assertSame(trim($this->payload()['resolution_note']), $warning->fresh()->resolution_note);
        foreach ($tables as $table) {
            $this->assertSame($before[$table], DB::table($table)->get()->toJson(), $table);
        }
        $this->assertSame($otherWarnings, DB::table('help_application_duplicate_warnings')->where('id', '<>', $warning->id)->get()->toJson());
        $audit = AuditLog::sole();
        $this->assertSame('help_application.duplicate_warning_resolved', $audit->action);
        $this->assertSame(Warning::class, $audit->subject_type);
        $this->assertSame($warning->id, $audit->subject_id);
        $this->assertSame($admin->id, $audit->actor_id);
        $this->assertSame(['status' => 'unreviewed'], $audit->old_values);
        $this->assertSame(['status' => $outcome], $audit->new_values);
        Bus::assertNothingDispatched();
        Mail::assertNothingSent();
        Notification::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_boundary_length_notes_and_preserved_internal_whitespace(): void
    {
        foreach ([10, 1000] as $length) {
            [$admin, $app, $prior, $warning] = $this->fixture();
            $note = str_repeat('ن', $length);
            $this->actingAs($admin)->post($this->resolveUrl($app, $warning), ['outcome' => 'dismissed', 'resolution_note' => '  '.$note.'  '])->assertRedirect($this->indexUrl($app));
            $this->assertSame($note, $warning->fresh()->resolution_note);
        }
    }

    public function test_first_resolution_wins_for_same_opposite_and_later_administrator(): void
    {
        [$admin, $app, $prior, $warning] = $this->fixture();
        $service = app(HelpApplicationDuplicateWarningResolutionService::class);
        $this->assertTrue($service->resolve($admin, $app->reference, $warning->reference, 'dismissed', 'Original private note')->changed);
        $before = (array) DB::table('help_application_duplicate_warnings')->find($warning->id);
        foreach (['dismissed', 'confirmed_match'] as $outcome) {
            $this->actingAs(User::factory()->superAdmin()->create())->post($this->resolveUrl($app, $warning), $this->payload($outcome))
                ->assertSessionHas('status', 'Warning already resolved. / تم حسم التحذير بالفعل.');
            $this->assertSame($before, (array) DB::table('help_application_duplicate_warnings')->find($warning->id));
        }
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_audit_failure_rolls_back_entire_warning(): void
    {
        [$admin, $app, $prior, $warning] = $this->fixture();
        $before = (array) DB::table('help_application_duplicate_warnings')->find($warning->id);
        $this->mock(AuditLogger::class)->shouldReceive('log')->once()->andReturnUsing(function (...$arguments) {
            (new AuditLogger)->log(...$arguments);
            throw new RuntimeException('Synthetic audit failure');
        });
        try {
            app(HelpApplicationDuplicateWarningResolutionService::class)->resolve($admin, $app->reference, $warning->reference, 'dismissed', 'A valid private note');
            $this->fail('Expected rollback');
        } catch (RuntimeException $exception) {
            $this->assertSame('Synthetic audit failure', $exception->getMessage());
        }
        $this->assertSame($before, (array) DB::table('help_application_duplicate_warnings')->find($warning->id));
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public static function malformedWarnings(): array
    {
        return [
            [['resolved_by' => 'actor']], [['resolved_at' => '2026-01-01 00:00:00']], [['resolution_note' => 'raw inconsistent']],
            [['status' => 'confirmed_match']], [['status' => 'dismissed', 'resolved_at' => '2026-01-01 00:00:00']],
            [['status' => 'confirmed_match', 'resolution_note' => 'raw inconsistent']], [['status' => 'unsupported']], [['matched_application_id' => 'self']],
        ];
    }

    #[DataProvider('malformedWarnings')]
    public function test_inconsistent_warning_fails_without_mutation(array $changes): void
    {
        [$admin, $app, $prior, $warning] = $this->fixture();
        if (($changes['resolved_by'] ?? null) === 'actor') {
            $changes['resolved_by'] = $admin->id;
        }
        if (($changes['matched_application_id'] ?? null) === 'self') {
            $changes['matched_application_id'] = $app->id;
        }
        DB::table('help_application_duplicate_warnings')->where('id', $warning->id)->update($changes);
        $before = (array) DB::table('help_application_duplicate_warnings')->find($warning->id);
        $this->actingAs($admin)->post($this->resolveUrl($app, $warning), $this->payload())->assertNotFound();
        $this->assertSame($before, (array) DB::table('help_application_duplicate_warnings')->find($warning->id));
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public static function malformedLifecycles(): array
    {
        return array_map(fn ($value) => [$value], [
            ['open_slot' => null], ['submitted_at' => null], ['review_started_at' => null], ['status_changed_at' => null],
            ['status_changed_at' => '2000-01-01 00:00:00'], ['decided_by' => 'actor'], ['decided_at' => '2026-01-01 00:00:00'], ['appeal_eligibility_ended_at' => '2026-01-01 00:00:00'],
        ]);
    }

    #[DataProvider('malformedLifecycles')]
    public function test_malformed_lifecycle_fails_closed(array $changes): void
    {
        [$admin, $app, $prior, $warning] = $this->fixture();
        if (($changes['decided_by'] ?? null) === 'actor') {
            $changes['decided_by'] = $admin->id;
        }
        DB::table('help_applications')->where('id', $app->id)->update($changes);
        $this->actingAs($admin)->post($this->resolveUrl($app, $warning), $this->payload())->assertNotFound();
        $this->assertSame('unreviewed', $warning->fresh()->status->value);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_forms_history_deleted_resolver_and_detail_link(): void
    {
        [$admin, $app, $prior, $warning] = $this->fixture();
        $page = $this->actingAs($admin)->get($this->indexUrl($app))->assertOk();
        $page->assertSee('name="_token"', false)->assertSee('name="outcome"', false)->assertSee('name="resolution_note"', false)
            ->assertDontSee(' selected', false)->assertDontSee('aria-invalid', false)->assertDontSee('aria-describedby', false);
        $this->assertSame(1, substr_count($page->getContent(), 'name="resolution_note"'));
        $this->get(route('admin.help-applications.in-review.show', $app->reference))->assertSee($this->indexUrl($app), false);
        $this->post($this->resolveUrl($app, $warning), $this->payload());
        $admin->delete();
        $this->actingAs(User::factory()->superAdmin()->create())->get($this->indexUrl($app))->assertOk()
            ->assertSee('Confirmed match / تطابق مؤكد')->assertSee('A deliberate  private note.')
            ->assertSee('All possible matches have been reviewed. / تمت مراجعة جميع المطابقات المحتملة.')
            ->assertDontSee('name="resolution_note"', false)->assertDontSee($admin->name);
    }

    public static function staleChanges(): array
    {
        return [['status'], ['reviewed_by']];
    }

    #[DataProvider('staleChanges')]
    public function test_single_connection_stale_primary_read_suppresses_warning_and_prior_data(string $field): void
    {
        [$admin, $app, $prior, $warning] = $this->fixture();
        $other = User::factory()->admin()->create();
        $fired = false;
        DB::listen(function ($query) use (&$fired, $field, $app, $other) {
            if (! $fired && str_starts_with($query->sql, 'select "reference", "status", "reviewed_by" from "help_applications"')) {
                $fired = true;
                DB::table('help_applications')->where('id', $app->id)->update([$field => $field === 'status' ? 'pending' : $other->id]);
            }
        });
        $this->actingAs($admin)->get($this->indexUrl($app))->assertOk()->assertDontSee($warning->reference)
            ->assertDontSee($prior->reference)->assertDontSee('Prior sentinel')->assertDontSee('name="resolution_note"', false);
        $this->assertTrue($fired);
    }

    public function test_fresh_locked_actor_is_reauthorized_and_lock_queries_are_minimal_and_ordered(): void
    {
        [$admin, $app, $prior, $warning] = $this->fixture();
        DB::table('users')->where('id', $admin->id)->update(['is_active' => false]);
        DB::enableQueryLog();
        try {
            app(HelpApplicationDuplicateWarningResolutionService::class)->resolve($admin, $app->reference, $warning->reference, 'dismissed', 'A valid private note');
            $this->fail('Stale actor was accepted');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
        $queries = array_column(DB::getQueryLog(), 'query');
        $this->assertStringContainsString('from "help_applications"', $queries[0]);
        $this->assertStringContainsString('from "users"', $queries[1]);
        $this->assertStringNotContainsString('help_application_duplicate_warnings', implode(' ', $queries));
        DB::table('users')->where('id', $admin->id)->update(['is_active' => true]);
        DB::flushQueryLog();
        app(HelpApplicationDuplicateWarningResolutionService::class)->resolve($admin, $app->reference, $warning->reference, 'dismissed', 'A valid private note');
        $queries = array_column(DB::getQueryLog(), 'query');
        $this->assertStringContainsString('from "help_applications"', $queries[0]);
        $this->assertStringContainsString('from "users"', $queries[1]);
        $this->assertStringContainsString('from "help_application_duplicate_warnings"', $queries[2]);
        foreach (['full_name', 'identity_document_number', 'identity_blind_index', 'email', 'category', 'private_story', 'requested_amount'] as $field) {
            $this->assertStringNotContainsString($field, implode(' ', array_slice($queries, 0, 3)));
        }
    }

    public function test_single_connection_stale_warning_selection_cannot_replace_the_winner(): void
    {
        [$admin, $app, $prior, $warning] = $this->fixture();
        $staleSelection = Warning::findOrFail($warning->id);
        $this->assertSame('unreviewed', $staleSelection->status->value);
        $service = app(HelpApplicationDuplicateWarningResolutionService::class);
        $this->assertTrue($service->resolve($admin, $app->reference, $warning->reference, 'confirmed_match', 'First winning private note')->changed);
        $before = (array) DB::table('help_application_duplicate_warnings')->find($warning->id);
        $this->assertFalse($service->resolve($admin, $app->reference, $staleSelection->reference, 'dismissed', 'Later private note')->changed);
        $this->assertSame($before, (array) DB::table('help_application_duplicate_warnings')->find($warning->id));
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_multiple_forms_only_restore_the_valid_outcome_for_the_failed_warning(): void
    {
        [$admin, $app, $prior, $warning] = $this->fixture();
        Warning::factory()->create(['submitted_application_id' => $app->id]);
        $this->actingAs($admin)->post($this->resolveUrl($app, $warning), ['outcome' => 'dismissed', 'resolution_note' => 'secret']);
        $page = $this->get($this->indexUrl($app))->assertOk()->assertDontSee('>secret</textarea>', false);
        $this->assertSame(1, substr_count($page->getContent(), ' selected'));
        $this->assertSame(1, substr_count($page->getContent(), 'aria-invalid="true"'));
        $this->assertSame(1, substr_count($page->getContent(), 'id="note-error-'.$warning->reference.'"'));
        $this->assertSame(2, substr_count($page->getContent(), 'name="resolution_note"'));
    }

    public function test_compiled_error_classes_exist_without_building_assets(): void
    {
        $css = implode('', array_map('file_get_contents', glob(public_path('build/assets/*.css'))));
        foreach (['.border-red-200', '.focus\\:border-red-500', '.focus\\:ring-red-500', '.text-red-800'] as $selector) {
            $this->assertStringContainsString($selector, $css);
        }
    }

    public function test_resolved_note_is_escaped_and_corrupt_note_is_concealed(): void
    {
        [$admin, $app, $prior, $warning] = $this->fixture();
        $this->actingAs($admin)->post($this->resolveUrl($app, $warning), ['outcome' => 'dismissed', 'resolution_note' => '<script>Private resolution</script>']);
        $this->get($this->indexUrl($app))->assertOk()->assertSee('&lt;script&gt;Private resolution&lt;/script&gt;', false)
            ->assertDontSee('<script>Private resolution</script>', false)->assertDontSee('name="resolution_note"', false);
        DB::table('help_application_duplicate_warnings')->where('id', $warning->id)->update(['resolution_note' => 'corrupt-private-note']);
        $this->get($this->indexUrl($app))->assertNotFound()->assertDontSee('corrupt-private-note');
    }

    public function test_all_summary_counts_update_after_resolution(): void
    {
        [$admin, $app, $prior, $warning] = $this->fixture();
        Warning::factory()->confirmed()->create(['submitted_application_id' => $app->id]);
        Warning::factory()->dismissed()->create(['submitted_application_id' => $app->id]);
        $page = $this->actingAs($admin)->get($this->indexUrl($app))->assertOk()->assertDontSee('All possible matches have been reviewed.');
        $this->assertEquals(['confirmed_match' => 1, 'dismissed' => 1, 'unreviewed' => 1], $page->viewData('counts')->all());
        $this->post($this->resolveUrl($app, $warning), $this->payload('dismissed'));
        $page = $this->get($this->indexUrl($app))->assertOk()->assertSee('All possible matches have been reviewed.');
        $this->assertEquals(['confirmed_match' => 1, 'dismissed' => 2], $page->viewData('counts')->all());
    }

    public function test_applicant_interface_does_not_disclose_warning_or_resolution(): void
    {
        [$admin, $app, $prior, $warning] = $this->fixture();
        app(HelpApplicationDuplicateWarningResolutionService::class)->resolve($admin, $app->reference, $warning->reference, 'confirmed_match', 'Hidden resolution sentinel');
        $this->actingAs(User::findOrFail($app->applicant_id))->get(route('help-applications.index'))->assertOk()
            ->assertDontSee($warning->reference)->assertDontSee($prior->reference)->assertDontSee('Hidden resolution sentinel')->assertDontSee('confirmed_match');
    }

    public function test_invalid_repetition_still_validates_without_altering_history(): void
    {
        [$admin, $app, $prior, $warning] = $this->fixture();
        $this->actingAs($admin)->post($this->resolveUrl($app, $warning), $this->payload());
        $before = (array) DB::table('help_application_duplicate_warnings')->find($warning->id);
        $response = $this->post($this->resolveUrl($app, $warning), []);
        $response->assertRedirect($this->indexUrl($app));
        $this->assertTrue(session('errors')->getBag('warning_'.$warning->reference)->has('outcome'));
        $this->assertSame($before, (array) DB::table('help_application_duplicate_warnings')->find($warning->id));
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_existing_removed_and_foreign_document_metadata_is_never_read(): void
    {
        [$admin, $app, $prior, $warning] = $this->fixture();
        foreach ([$app, $prior] as $owner) {
            HelpApplicationDocument::factory()->removedBy($admin)->create([
                'help_application_id' => $owner->id, 'original_name' => 'SECRET-DOCUMENT-NAME.pdf',
                'storage_path' => 'SECRET-DOCUMENT-PATH-'.$owner->reference, 'checksum' => str_repeat('a', 64),
            ]);
        }
        Storage::shouldReceive('disk')->never();
        DB::enableQueryLog();
        $this->actingAs($admin)->get($this->indexUrl($app))->assertOk()->assertDontSee('SECRET-DOCUMENT-NAME')->assertDontSee('SECRET-DOCUMENT-PATH');
        $this->post($this->resolveUrl($app, $warning), $this->payload())->assertRedirect($this->indexUrl($app));
        $sql = implode(' ', array_column(DB::getQueryLog(), 'query'));
        foreach (['help_application_documents', 'campaigns', 'internal_notification', 'storage_path', 'checksum'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $sql);
        }
    }

    public function test_resolution_does_not_depend_on_or_modify_category_assignment(): void
    {
        [$admin, $app, $prior, $warning] = $this->fixture();
        $category = Category::factory()->create();
        DB::table('help_applications')->where('id', $app->id)->update([
            'category_id' => $category->id, 'category_assigned_by' => $admin->id, 'category_assigned_at' => now(),
        ]);
        $before = (array) DB::table('help_applications')->find($app->id);
        $this->actingAs($admin)->get($this->indexUrl($app))->assertOk()->assertDontSee($category->name_en)->assertDontSee($category->name_ar);
        $this->post($this->resolveUrl($app, $warning), $this->payload())->assertRedirect($this->indexUrl($app));
        $this->assertSame($before, (array) DB::table('help_applications')->find($app->id));
    }

    public function test_head_response_has_exact_private_headers(): void
    {
        [$admin, $app] = $this->fixture();
        $this->actingAs($admin)->head($this->indexUrl($app))->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Pragma', 'no-cache');
    }

    public function test_zero_warning_page_displays_only_the_empty_match_state(): void
    {
        $admin = User::factory()->admin()->create();
        $app = HelpApplication::factory()->underReview()->create(['reviewed_by' => $admin->id]);

        $this->actingAs($admin)->get($this->indexUrl($app))->assertOk()
            ->assertSee('No possible matches. / لا توجد مطابقات محتملة.')
            ->assertDontSee('A stored identity match was detected. The identity number is not displayed.')
            ->assertDontSee('تم اكتشاف تطابق مخزن للهوية. لا يتم عرض رقم الهوية.')
            ->assertDontSee('All possible matches have been reviewed.')
            ->assertDontSee('تمت مراجعة جميع المطابقات المحتملة.')
            ->assertDontSee('name="resolution_note"', false)
            ->assertDontSee('/resolve', false);
    }

    public function test_one_unreviewed_warning_displays_match_explanation_and_exactly_one_resolution_form(): void
    {
        [$admin, $app, $prior, $warning] = $this->fixture();

        $page = $this->actingAs($admin)->get($this->indexUrl($app))->assertOk()
            ->assertSee('A stored identity match was detected. The identity number is not displayed.')
            ->assertSee('تم اكتشاف تطابق مخزن للهوية. لا يتم عرض رقم الهوية.')
            ->assertDontSee('No possible matches.')
            ->assertDontSee('لا توجد مطابقات محتملة.')
            ->assertDontSee('All possible matches have been reviewed.')
            ->assertDontSee('تمت مراجعة جميع المطابقات المحتملة.');
        $this->assertSame(1, substr_count($page->getContent(), 'action="'.$this->resolveUrl($app, $warning).'"'));
        $this->assertSame(1, substr_count($page->getContent(), 'name="resolution_note"'));
    }

    public function test_resolved_warnings_display_match_explanation_and_all_reviewed_state_without_forms(): void
    {
        $admin = User::factory()->admin()->create();
        $app = HelpApplication::factory()->underReview()->create(['reviewed_by' => $admin->id]);
        Warning::factory()->confirmed()->create(['submitted_application_id' => $app->id]);
        Warning::factory()->dismissed()->create(['submitted_application_id' => $app->id]);

        $this->actingAs($admin)->get($this->indexUrl($app))->assertOk()
            ->assertSee('A stored identity match was detected. The identity number is not displayed.')
            ->assertSee('تم اكتشاف تطابق مخزن للهوية. لا يتم عرض رقم الهوية.')
            ->assertSee('All possible matches have been reviewed. / تمت مراجعة جميع المطابقات المحتملة.')
            ->assertDontSee('No possible matches.')
            ->assertDontSee('لا توجد مطابقات محتملة.')
            ->assertDontSee('name="resolution_note"', false)
            ->assertDontSee('/resolve', false);
    }

    public static function detailWarningCounts(): array
    {
        return ['zero' => [0], 'one' => [1], 'multiple' => [3]];
    }

    private function assertDetailWarningLink(string $html, HelpApplication $app, int $count): void
    {
        $url = $this->indexUrl($app);
        $this->assertSame(1, substr_count($html, 'href="'.$url.'"'));
        $this->assertStringNotContainsString('/in-review/'.$app->id.'/duplicate-warnings', $html);
        preg_match('/<section[^>]*aria-labelledby="matches"[^>]*>(.*?)<\/section>/s', $html, $section);
        $this->assertStringContainsString('<a class="text-indigo-600" href="'.$url.'">Review possible matches ('.$count.') / مراجعة المطابقات المحتملة ('.$count.')</a>', $section[1] ?? '');
        $this->assertStringContainsString('عدد تحذيرات التكرار', $section[1] ?? '');
        $this->assertStringNotContainsString('<form', $section[1] ?? '');
        $this->assertStringNotContainsString('<button', $section[1] ?? '');
    }

    #[DataProvider('detailWarningCounts')]
    public function test_detail_warning_link_reuses_exact_authorized_count(int $count): void
    {
        $admin = User::factory()->admin()->create();
        $app = HelpApplication::factory()->underReview()->create(['reviewed_by' => $admin->id]);
        $warnings = Warning::factory()->count($count)->create(['submitted_application_id' => $app->id]);
        $page = $this->actingAs($admin)->get(route('admin.help-applications.in-review.show', $app->reference))->assertOk();
        $this->assertDetailWarningLink($page->getContent(), $app, $count);
        foreach ($warnings as $warning) {
            $page->assertDontSee($warning->reference);
        }
    }

    public function test_detail_warning_link_remains_after_all_warnings_are_resolved(): void
    {
        [$admin, $app, $prior, $warning] = $this->fixture();
        app(HelpApplicationDuplicateWarningResolutionService::class)->resolve($admin, $app->reference, $warning->reference, 'dismissed', 'Private resolved note');
        $page = $this->actingAs($admin)->get(route('admin.help-applications.in-review.show', $app->reference))->assertOk()
            ->assertDontSee('Private resolved note')->assertDontSee($warning->reference);
        $this->assertDetailWarningLink($page->getContent(), $app, 1);
    }

    public function test_detail_warning_link_respects_admin_and_super_admin_scope(): void
    {
        [$admin, $app, $prior, $warning] = $this->fixture();
        $other = User::factory()->admin()->create();
        $url = route('admin.help-applications.in-review.show', $app->reference);
        $this->actingAs($other)->get($url)->assertNotFound()->assertDontSee($this->indexUrl($app), false)->assertDontSee($warning->reference);
        $this->get($this->indexUrl($app))->assertNotFound();
        $super = User::factory()->superAdmin()->create();
        $page = $this->actingAs($super)->get($url)->assertOk();
        $this->assertDetailWarningLink($page->getContent(), $app, 1);
        $admin->delete();
        $page = $this->get($url)->assertOk();
        $this->assertDetailWarningLink($page->getContent(), $app, 1);
        $this->actingAs($other)->get($url)->assertNotFound()->assertDontSee($this->indexUrl($app), false);
    }

    public function test_applicant_pages_never_receive_the_detail_warning_link_or_count(): void
    {
        [$admin, $app, $prior, $warning] = $this->fixture();
        $this->actingAs(User::findOrFail($app->applicant_id))->get(route('help-applications.index'))->assertOk()
            ->assertDontSee('duplicate-warnings')->assertDontSee('Duplicate-warning count')->assertDontSee('عدد تحذيرات التكرار')
            ->assertDontSee('Review possible matches')->assertDontSee('مراجعة المطابقات المحتملة')
            ->assertDontSee($warning->reference)->assertDontSee($prior->reference);
    }

    public function test_following_zero_count_detail_link_reaches_only_the_empty_warning_state(): void
    {
        $admin = User::factory()->admin()->create();
        $app = HelpApplication::factory()->underReview()->create(['reviewed_by' => $admin->id]);
        $page = $this->actingAs($admin)->get(route('admin.help-applications.in-review.show', $app->reference))->assertOk();
        preg_match('/href="([^"]*\/duplicate-warnings)"/', $page->getContent(), $link);
        $this->assertSame($this->indexUrl($app), $link[1] ?? null);
        $this->get($link[1])->assertOk()->assertSee('No possible matches. / لا توجد مطابقات محتملة.')
            ->assertDontSee('A stored identity match was detected.')->assertDontSee('تم اكتشاف تطابق مخزن للهوية.')
            ->assertDontSee('name="resolution_note"', false)->assertDontSee('/resolve', false);
    }

    public function test_rendering_detail_warning_link_has_no_effects_and_reuses_one_count_query(): void
    {
        [$admin, $app, $prior, $warning] = $this->fixture();
        $tables = ['help_applications', 'help_application_duplicate_warnings', 'audit_logs', 'help_application_documents',
            'internal_notification_events', 'internal_notification_event_recipients', 'internal_notifications', 'campaigns'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->get()->toJson();
        }
        Bus::fake();
        Mail::fake();
        Notification::fake();
        Queue::fake();
        Storage::shouldReceive('disk')->never();
        $broadcasts = [];
        Event::listen('*', function ($name, $payload) use (&$broadcasts) {
            foreach ($payload as $event) {
                if ($event instanceof ShouldBroadcast) {
                    $broadcasts[] = $event;
                }
            }
        });
        DB::enableQueryLog();
        $page = $this->actingAs($admin)->get(route('admin.help-applications.in-review.show', $app->reference))->assertOk();
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();
        $warningQueries = array_values(array_filter($queries, fn ($sql) => str_contains($sql, 'help_application_duplicate_warnings')));
        $this->assertCount(1, $warningQueries);
        $this->assertStringContainsString('count(*)', $warningQueries[0]);
        $this->assertStringNotContainsString('campaigns', implode(' ', $queries));
        $this->assertDetailWarningLink($page->getContent(), $app, 1);
        foreach ($tables as $table) {
            $this->assertSame($before[$table], DB::table($table)->get()->toJson(), $table);
        }
        $this->assertSame([], $broadcasts);
        Bus::assertNothingDispatched();
        Mail::assertNothingSent();
        Notification::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_detail_warning_link_class_exists_in_compiled_css(): void
    {
        $css = implode('', array_map('file_get_contents', glob(public_path('build/assets/*.css'))));
        $this->assertStringContainsString('.text-indigo-600', $css);
    }

    public function test_warning_heading_isolates_one_escaped_uuid_from_bilingual_labels(): void
    {
        [$admin, $app, $prior, $warning] = $this->fixture();
        $page = $this->actingAs($admin)->get($this->indexUrl($app))->assertOk();
        preg_match('/<h2[^>]*>(.*?)<\/h2>/s', $page->getContent(), $heading);
        $this->assertSame('Warning reference / <span lang="ar" dir="rtl">مرجع التحذير</span>: <bdi dir="ltr">'.e($warning->reference).'</bdi>', $heading[1] ?? null);
        $this->assertSame(1, substr_count($heading[1], '<bdi dir="ltr">'));
        $this->assertSame(1, substr_count($heading[1], $warning->reference));
        // UUIDs in form attributes remain necessary; visible heading text must not duplicate it.
        $this->assertSame(1, substr_count(strip_tags($page->getContent()), $warning->reference));
    }

    public function test_comparison_references_remain_in_separate_escaped_definition_blocks(): void
    {
        [$admin, $app, $prior] = $this->fixture();
        $page = $this->actingAs($admin)->get($this->indexUrl($app))->assertOk();
        foreach ([$app->reference, $prior->reference] as $reference) {
            $this->assertSame(1, substr_count($page->getContent(), '<dt class="text-sm text-gray-500">Reference / المرجع</dt><dd class="break-all">'.e($reference).'</dd>'));
            $this->assertSame(1, substr_count(strip_tags($page->getContent()), $reference));
        }
    }

    public function test_stored_reference_like_markup_is_escaped_in_heading_and_comparison(): void
    {
        [$admin, $app, $prior, $warning] = $this->fixture();
        $warningText = '<img src=x onerror=alert(1)>';
        $priorText = '<script>alert(2)</script>';
        DB::table('help_application_duplicate_warnings')->where('id', $warning->id)->update(['reference' => $warningText]);
        DB::table('help_applications')->where('id', $prior->id)->update(['reference' => $priorText]);
        $page = $this->actingAs($admin)->get($this->indexUrl($app))->assertOk()
            ->assertSee('<bdi dir="ltr">'.e($warningText).'</bdi>', false)
            ->assertSee('<dd class="break-all">'.e($priorText).'</dd>', false)
            ->assertDontSee($warningText, false)->assertDontSee($priorText, false);
        // A malformed current reference cannot enter through the UUID-constrained route.
        // Exercise its existing escaped view projection directly as well.
        $warnings = $page->viewData('warnings');
        $warnings->first()->current->reference = '<svg onload=alert(3)>';
        $html = view('admin.help-applications.in-review.duplicate-warnings', [
            'reference' => $app->reference, 'warnings' => $warnings, 'counts' => $page->viewData('counts'),
        ])->render();
        $this->assertStringContainsString('<dd class="break-all">'.e('<svg onload=alert(3)>').'</dd>', $html);
        $this->assertStringNotContainsString('<svg onload=alert(3)>', $html);
    }
}
