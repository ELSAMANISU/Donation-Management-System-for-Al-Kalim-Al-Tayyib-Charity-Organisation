<?php

namespace Tests\Feature\Admin;

use App\Enums\HelpApplicationDocumentPurpose;
use App\Enums\HelpApplicationStatus;
use App\Models\HelpApplication;
use App\Models\HelpApplicationDocument;
use App\Models\HelpApplicationDuplicateWarning;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HelpApplicationReadOnlyReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_routes_are_exact_get_only_uuid_admin_surfaces(): void
    {
        $routes = collect(app('router')->getRoutes())->filter(
            fn ($route) => str_starts_with((string) $route->getName(), 'admin.help-applications.'),
        );

        $this->assertCount(2, $routes);
        $this->assertSame(['admin.help-applications.index', 'admin.help-applications.show'], $routes->pluck('action.as')->sort()->values()->all());
        foreach ($routes as $route) {
            $this->assertSame(['GET', 'HEAD'], $route->methods());
            $this->assertContains('web', $route->gatherMiddleware());
            $this->assertContains('auth', $route->gatherMiddleware());
            $this->assertContains('role:admin,super_admin', $route->gatherMiddleware());
        }
        $this->assertSame('[\\da-fA-F]{8}-[\\da-fA-F]{4}-[\\da-fA-F]{4}-[\\da-fA-F]{4}-[\\da-fA-F]{12}', $routes->firstWhere('action.as', 'admin.help-applications.show')->wheres['helpApplication']);

        $index = $routes->firstWhere('action.as', 'admin.help-applications.index');
        $show = $routes->firstWhere('action.as', 'admin.help-applications.show');
        $this->assertSame('admin/help-applications', $index->uri());
        $this->assertSame('admin/help-applications/{helpApplication}', $show->uri());
        $this->assertSame('App\\Http\\Controllers\\Admin\\HelpApplicationController@index', $index->getActionName());
        $this->assertSame('App\\Http\\Controllers\\Admin\\HelpApplicationController@show', $show->getActionName());

        $allRoutes = collect(app('router')->getRoutes())->values();
        $publicWildcardPosition = $allRoutes->search(fn ($route) => $route->uri() === '{locale}/cases/{id}');
        $this->assertLessThan($publicWildcardPosition, $allRoutes->search(fn ($route) => $route->getName() === 'admin.help-applications.index'));
        $this->assertLessThan($publicWildcardPosition, $allRoutes->search(fn ($route) => $route->getName() === 'admin.help-applications.show'));
        $this->assertEmpty($allRoutes->filter(fn ($route) => str_starts_with($route->uri(), 'admin/help-applications')
            && array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE'])));
        $this->assertEmpty($allRoutes->filter(fn ($route) => str_starts_with($route->uri(), 'admin/help-applications')
            && preg_match('/document|download|preview/i', $route->uri())));
    }

    public function test_guest_and_ordinary_user_are_blocked_from_both_pages(): void
    {
        $pending = HelpApplication::factory()->pending()->create(['full_name' => 'PRIVATE AUTH SENTINEL']);
        foreach ([route('admin.help-applications.index'), route('admin.help-applications.show', $pending->reference)] as $url) {
            $this->get($url)->assertRedirect(route('login'))->assertDontSee('PRIVATE AUTH SENTINEL');
        }
        $user = User::factory()->user()->create();
        foreach ([route('admin.help-applications.index'), route('admin.help-applications.show', $pending->reference)] as $url) {
            $this->actingAs($user)->get($url)->assertForbidden()->assertDontSee('PRIVATE AUTH SENTINEL');
        }
    }

    public function test_both_active_administrator_roles_can_access_queue_and_detail(): void
    {
        $pending = HelpApplication::factory()->pending()->create();
        foreach ([User::factory()->admin()->create(), User::factory()->superAdmin()->create()] as $actor) {
            $this->actingAs($actor)->get(route('admin.help-applications.index'))->assertOk();
            $this->get(route('admin.help-applications.show', $pending->reference))->assertOk();
        }
    }

    public function test_disabled_and_password_change_pending_administrators_are_stopped_before_content(): void
    {
        $pending = HelpApplication::factory()->pending()->create(['full_name' => 'PRIVATE BLOCK SENTINEL']);
        foreach ([
            User::factory()->admin()->disabled()->create(), User::factory()->superAdmin()->disabled()->create(),
            User::factory()->admin()->mustChangePassword()->create(), User::factory()->superAdmin()->mustChangePassword()->create(),
        ] as $actor) {
            $index = $this->actingAs($actor)->get(route('admin.help-applications.index'));
            $index->assertRedirect()->assertDontSee('PRIVATE BLOCK SENTINEL');
            $show = $this->actingAs($actor)->get(route('admin.help-applications.show', $pending->reference));
            $show->assertRedirect()->assertDontSee('PRIVATE BLOCK SENTINEL');
        }
    }

    public function test_every_non_pending_status_and_invalid_reference_is_concealed(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        foreach (HelpApplicationStatus::cases() as $status) {
            if ($status === HelpApplicationStatus::Pending) {
                continue;
            }
            $application = HelpApplication::factory()->create(['status' => $status]);
            $this->get(route('admin.help-applications.show', $application->reference))->assertNotFound();
        }
        foreach (['123', 'not-a-uuid', '00000000-0000-4000-8000-000000000000'] as $reference) {
            $this->get('/admin/help-applications/'.$reference)->assertNotFound();
        }
    }

    public function test_policy_keeps_applicant_and_administrator_abilities_separate(): void
    {
        $user = User::factory()->user()->create();
        $admin = User::factory()->admin()->create();
        $draft = HelpApplication::factory()->create(['applicant_id' => $user->getKey()]);
        $pending = HelpApplication::factory()->pending()->create();
        $this->assertTrue($user->can('create', HelpApplication::class));
        $this->assertTrue($user->can('update', $draft));
        $this->assertTrue($user->can('submit', $draft));
        $this->assertFalse($user->can('reviewPendingAny', HelpApplication::class));
        $this->assertTrue($admin->can('reviewPendingAny', HelpApplication::class));
        $this->assertTrue($admin->can('reviewPending', $pending));
        $this->assertFalse($admin->can('create', HelpApplication::class));
        $this->assertFalse($admin->can('update', $draft));
        $this->assertFalse($admin->can('submit', $draft));
    }

    public function test_queue_excludes_all_non_pending_statuses_and_uses_exact_descending_order(): void
    {
        $admin = User::factory()->admin()->create();
        foreach (HelpApplicationStatus::cases() as $status) {
            if ($status !== HelpApplicationStatus::Pending) {
                HelpApplication::factory()->create(['status' => $status, 'full_name' => 'ABSENT-'.$status->value]);
            }
        }
        $time = now()->subHour();
        $older = HelpApplication::factory()->pending()->create(['full_name' => 'OLDER', 'submitted_at' => $time->copy()->subMinute()]);
        $tieFirst = HelpApplication::factory()->pending()->create(['full_name' => 'TIE LOWER ID', 'submitted_at' => $time]);
        $tieSecond = HelpApplication::factory()->pending()->create(['full_name' => 'TIE HIGHER ID', 'submitted_at' => $time]);
        $newest = HelpApplication::factory()->pending()->create(['full_name' => 'NEWEST', 'submitted_at' => $time->copy()->addMinute()]);

        $response = $this->actingAs($admin)->get(route('admin.help-applications.index'));
        $response->assertSeeInOrder([$newest->reference, $tieSecond->reference, $tieFirst->reference, $older->reference]);
        foreach (HelpApplicationStatus::cases() as $status) {
            if ($status !== HelpApplicationStatus::Pending) {
                $response->assertDontSee('ABSENT-'.$status->value);
            }
        }
    }

    public function test_queue_ignores_unapproved_query_parameters_and_has_bilingual_empty_state(): void
    {
        $admin = User::factory()->admin()->create();
        $sentinel = '<script>QUERY_PRIVATE_SENTINEL</script>';
        $response = $this->actingAs($admin)->get(route('admin.help-applications.index', [
            'status' => $sentinel, 'sort' => $sentinel, 'per_page' => 1, 'identity' => $sentinel,
            'email' => $sentinel, 'phone' => $sentinel, 'q' => $sentinel,
        ]));
        $response->assertOk()->assertDontSee('QUERY_PRIVATE_SENTINEL')->assertSee('No pending help applications.')
            ->assertSee('لا توجد طلبات مساعدة قيد الانتظار.');
    }

    public function test_access_boundaries_and_pending_concealment_are_exact(): void
    {
        $pending = HelpApplication::factory()->pending()->create();
        $draft = HelpApplication::factory()->create();

        $this->get(route('admin.help-applications.index'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->user()->create())->get(route('admin.help-applications.index'))->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())->get(route('admin.help-applications.index'))->assertOk();
        $this->actingAs(User::factory()->superAdmin()->create())->get(route('admin.help-applications.show', $pending->reference))->assertOk();
        $this->get(route('admin.help-applications.show', $draft->reference))->assertNotFound();
        $this->get('/admin/help-applications/123')->assertNotFound();
        $this->get('/admin/help-applications/00000000-0000-4000-8000-000000000000')->assertNotFound();
    }

    public function test_queue_is_pending_newest_first_paginated_and_private(): void
    {
        $admin = User::factory()->admin()->create();
        $draft = HelpApplication::factory()->create(['full_name' => 'DRAFT PRIVATE']);
        foreach (range(1, 26) as $offset) {
            HelpApplication::factory()->pending()->create([
                'full_name' => "Applicant {$offset}",
                'email' => "private{$offset}@example.test",
                'submitted_at' => now()->subMinutes($offset),
            ]);
        }

        $first = $this->actingAs($admin)->get(route('admin.help-applications.index'));
        $first->assertOk()->assertSeeInOrder(['Applicant 1', 'Applicant 25'])->assertDontSee('Applicant 26')->assertDontSee('DRAFT PRIVATE')->assertDontSee('private1@example.test');
        $this->get(route('admin.help-applications.index', ['page' => 2]))->assertOk()->assertSee('Applicant 26')->assertDontSee('Applicant 25');
        $this->assertSame('no-store, private', $first->headers->get('Cache-Control'));
        $this->assertSame('no-cache', $first->headers->get('Pragma'));
        $this->assertNotNull($draft);
    }

    public function test_detail_uses_only_approved_metadata_without_identity_or_storage_access(): void
    {
        $admin = User::factory()->admin()->create();
        $application = HelpApplication::factory()->pending()->create([
            'full_name' => 'Approved Display Name',
            'identity_document_number' => 'secret-number',
            'identity_blind_index' => 'secret-blind-index',
            'private_story' => "Line one\nLine two",
        ]);
        DB::table('help_applications')->where('reference', $application->reference)->update(['identity_document_number' => 'corrupt-ciphertext']);
        $document = HelpApplicationDocument::factory()->acceptedUnscanned()->medicalReport()->create([
            'help_application_id' => $application->getKey(),
            'original_name' => 'visible-name.pdf',
            'storage_path' => 'missing/private/secret-path.pdf',
            'checksum' => 'secret-checksum',
        ]);
        HelpApplicationDocument::factory()->acceptedUnscanned()->removedBy($admin)->create([
            'help_application_id' => $application->getKey(),
            'original_name' => 'removed-name.pdf',
            'purpose' => HelpApplicationDocumentPurpose::Other,
        ]);
        HelpApplicationDuplicateWarning::factory()->count(2)->create(['submitted_application_id' => $application->getKey()]);

        $before = [DB::table('help_applications')->count(), DB::table('help_application_documents')->count(), DB::table('help_application_duplicate_warnings')->count()];
        $response = $this->actingAs($admin)->get(route('admin.help-applications.show', $application->reference));
        $response->assertOk()->assertSee('Approved Display Name')->assertSee('visible-name.pdf')->assertSee('Medical report')->assertSee('PDF')->assertSee('1,024 bytes')->assertSee('later authorized review: 2');
        $response->assertDontSee('secret-number')->assertDontSee('corrupt-ciphertext')->assertDontSee('secret-blind-index')->assertDontSee('secret-path')->assertDontSee('secret-checksum')->assertDontSee('removed-name.pdf');
        $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));
        $this->assertSame('no-cache', $response->headers->get('Pragma'));
        $this->assertSame($before, [DB::table('help_applications')->count(), DB::table('help_application_documents')->count(), DB::table('help_application_duplicate_warnings')->count()]);
        $this->assertNotNull($document);
    }

    public function test_queue_html_contains_only_approved_work_queue_fields(): void
    {
        $admin = User::factory()->admin()->create();
        $application = HelpApplication::factory()->pending()->create([
            'full_name' => 'QUEUE APPROVED NAME', 'email' => 'queue-email-sentinel@example.test',
            'phone' => 'QUEUE-PHONE-SENTINEL', 'address' => 'QUEUE-ADDRESS-SENTINEL',
            'date_of_birth' => '1981-02-03', 'identity_issuing_country' => 'ZZ',
            'identity_document_number' => 'QUEUE-ID-NUMBER', 'identity_blind_index' => 'QUEUE-BLIND',
            'identity_blind_index_version' => 9876, 'requested_amount' => '987654.32',
            'private_story' => 'QUEUE-STORY-SENTINEL', 'preferred_receiving_method' => 'QUEUE-RECEIVING-SENTINEL',
            'consent_version' => 'QUEUE-CONSENT-SENTINEL', 'consented_at' => now()->subYears(3),
        ]);
        DB::table('help_applications')->where('reference', $application->reference)->update(['identity_document_number' => 'QUEUE-CORRUPT-CIPHERTEXT']);
        HelpApplicationDocument::factory()->acceptedUnscanned()->medicalReport()->create([
            'help_application_id' => $application->getKey(), 'original_name' => 'QUEUE-DOCUMENT-NAME',
            'storage_path' => 'QUEUE-STORAGE-PATH', 'checksum' => 'QUEUE-CHECKSUM', 'size_bytes' => 7654321,
        ]);
        HelpApplicationDuplicateWarning::factory()->create(['submitted_application_id' => $application->getKey(), 'resolution_note' => 'QUEUE-WARNING-NOTE']);

        $html = $this->actingAs($admin)->get(route('admin.help-applications.index'))->assertOk()->getContent();
        $this->assertStringContainsString('QUEUE APPROVED NAME', $html);
        $this->assertStringContainsString($application->reference, $html);
        $this->assertStringContainsString(route('admin.help-applications.show', $application->reference), $html);
        foreach (['queue-email-sentinel', 'QUEUE-PHONE', 'QUEUE-ADDRESS', '1981-02-03', 'national_id', 'ZZ',
            'QUEUE-CORRUPT', 'QUEUE-BLIND', '9876', '987,654.32', 'QUEUE-STORY', 'QUEUE-RECEIVING',
            'QUEUE-CONSENT', 'QUEUE-DOCUMENT', 'QUEUE-STORAGE', 'QUEUE-CHECKSUM', '7,654,321',
            'QUEUE-WARNING-NOTE', 'Possible prior-application matches'] as $private) {
            $this->assertStringNotContainsString($private, $html);
        }
    }

    public function test_detail_renders_every_approved_field_label_and_escapes_private_text(): void
    {
        $application = HelpApplication::factory()->pending()->create([
            'full_name' => '<script>NAME-XSS</script>', 'email' => 'approved@example.test', 'phone' => '+249111222333',
            'address' => '<b>ADDRESS-XSS</b>', 'date_of_birth' => '1992-04-05', 'identity_issuing_country' => 'SD',
            'requested_amount' => '12345.67', 'private_story' => "<img src=x onerror=STORY-XSS>\nsecond line",
            'preferred_receiving_method' => '<svg onload=METHOD-XSS>',
        ]);
        $response = $this->actingAs(User::factory()->admin()->create())->get(route('admin.help-applications.show', $application->reference));
        $response->assertOk()->assertSee($application->reference)->assertSee('Pending /')->assertSee('approved@example.test')
            ->assertSee('+249111222333')->assertSee('1992-04-05')->assertSee('National ID')->assertSee('SD')
            ->assertSee('12,345.67 SDG')->assertSee('Anonymous')->assertSee('&lt;script&gt;NAME-XSS&lt;/script&gt;', false)
            ->assertSee('&lt;b&gt;ADDRESS-XSS&lt;/b&gt;', false)->assertSee('&lt;img src=x onerror=STORY-XSS&gt;', false)
            ->assertSee('&lt;svg onload=METHOD-XSS&gt;', false)->assertDontSee('<script>NAME-XSS</script>', false);
        foreach (['Application status', 'Contact information', 'Assistance details', 'Identity metadata',
            'Supporting-document metadata', 'Possible prior matches', 'Reference', 'Submitted', 'Full name',
            'Email', 'Phone', 'Address', 'Date of birth', 'Document type', 'Issuing country', 'Requested amount',
            'Private story', 'Preferred way to receive assistance', 'Public identity preference'] as $label) {
            $response->assertSee($label);
        }
    }

    public function test_document_metadata_formats_and_purposes_are_controlled_without_storage_calls(): void
    {
        Storage::shouldReceive('disk')->never();
        Storage::shouldReceive('exists')->never();
        Storage::shouldReceive('get')->never();
        Storage::shouldReceive('readStream')->never();
        Storage::shouldReceive('download')->never();
        Storage::shouldReceive('url')->never();
        Storage::shouldReceive('temporaryUrl')->never();

        $application = HelpApplication::factory()->pending()->create();
        $cases = [
            ['pdf', HelpApplicationDocumentPurpose::MedicalReport, 'Medical report'],
            ['jpg', HelpApplicationDocumentPurpose::CostEstimate, 'Cost estimate'],
            ['png', HelpApplicationDocumentPurpose::TuitionInvoice, 'Tuition invoice'],
            ['pdf', HelpApplicationDocumentPurpose::AdmissionLetter, 'Admission letter'],
            ['png', HelpApplicationDocumentPurpose::Other, 'Other evidence'],
        ];
        foreach ($cases as $index => [$format, $purpose, $label]) {
            HelpApplicationDocument::factory()->acceptedUnscanned()->{$format}()->create([
                'help_application_id' => $application->getKey(), 'purpose' => $purpose,
                'original_name' => "<script>FILE-{$index}</script>.{$format}", 'size_bytes' => 1024 + $index,
                'storage_path' => "../../public/STORAGE-{$index}", 'checksum' => "CHECKSUM-{$index}",
            ]);
        }
        $foreign = HelpApplicationDocument::factory()->acceptedUnscanned()->medicalReport()->create(['original_name' => 'FOREIGN-DOCUMENT']);
        $removed = HelpApplicationDocument::factory()->acceptedUnscanned()->medicalReport()->removedBy(User::factory()->admin()->create())->create([
            'help_application_id' => $application->getKey(), 'original_name' => 'REMOVED-DOCUMENT',
        ]);

        $response = $this->actingAs(User::factory()->admin()->create())->get(route('admin.help-applications.show', $application->reference));
        $response->assertOk()->assertSee('PDF')->assertSee('JPEG')->assertSee('PNG')->assertSee('1,024 bytes');
        foreach ($cases as $index => [, , $label]) {
            $response->assertSee($label)->assertSee("&lt;script&gt;FILE-{$index}&lt;/script&gt;", false);
        }
        foreach (['FOREIGN-DOCUMENT', 'REMOVED-DOCUMENT', 'STORAGE-', 'CHECKSUM-', '<script>FILE-', 'download', 'preview', 'iframe'] as $absent) {
            $response->assertDontSee($absent, false);
        }
        $this->assertNotNull($foreign);
        $this->assertNotNull($removed);
    }

    public function test_secondary_metadata_scope_rechecks_pending_status_after_main_read(): void
    {
        $application = HelpApplication::factory()->pending()->create();
        HelpApplicationDocument::factory()->acceptedUnscanned()->medicalReport()->create([
            'help_application_id' => $application->getKey(), 'original_name' => 'BETWEEN-READS-DOCUMENT',
        ]);
        HelpApplicationDuplicateWarning::factory()->create(['submitted_application_id' => $application->getKey()]);
        $changed = false;
        DB::listen(function ($query) use ($application, &$changed): void {
            if (! $changed && str_contains($query->sql, 'from "help_applications"') && str_contains($query->sql, '"full_name"')) {
                $changed = true;
                DB::table('help_applications')->where('reference', $application->reference)->update(['status' => HelpApplicationStatus::UnderReview->value]);
            }
        });

        $response = $this->actingAs(User::factory()->admin()->create())->get(route('admin.help-applications.show', $application->reference));
        $response->assertOk()->assertDontSee('BETWEEN-READS-DOCUMENT')->assertSee('No possible prior matches recorded.');
        $this->assertTrue($changed);
    }

    public function test_warning_counts_only_submitted_side_and_never_renders_warning_details(): void
    {
        $application = HelpApplication::factory()->pending()->create();
        $matched = HelpApplication::factory()->closed()->create(['full_name' => 'MATCHED-NAME-SENTINEL']);
        $raised = HelpApplicationDuplicateWarning::factory()->create([
            'submitted_application_id' => $application->getKey(), 'matched_application_id' => $matched->getKey(),
            'resolution_note' => 'WARNING-RESOLUTION-SENTINEL',
        ]);
        HelpApplicationDuplicateWarning::factory()->create(['matched_application_id' => $application->getKey()]);
        $response = $this->actingAs(User::factory()->admin()->create())->get(route('admin.help-applications.show', $application->reference));
        $response->assertSee('later authorized review: 1')->assertDontSee($matched->reference)->assertDontSee('MATCHED-NAME-SENTINEL')
            ->assertDontSee($raised->reference)->assertDontSee('WARNING-RESOLUTION-SENTINEL')->assertDontSee('fraud')
            ->assertDontSee('confirmed duplicate')->assertDontSee('resolve');
    }

    public function test_zero_and_multiple_warning_messages_are_exact(): void
    {
        $admin = User::factory()->admin()->create();
        $zero = HelpApplication::factory()->pending()->create();
        $this->actingAs($admin)->get(route('admin.help-applications.show', $zero->reference))
            ->assertSee('No possible prior matches recorded.')->assertSee('لا توجد مطابقات سابقة محتملة مسجلة.');
        $multiple = HelpApplication::factory()->pending()->create();
        HelpApplicationDuplicateWarning::factory()->count(3)->create(['submitted_application_id' => $multiple->getKey()]);
        $this->get(route('admin.help-applications.show', $multiple->reference))->assertSee('later authorized review: 3');
    }

    public function test_success_headers_are_exact_on_index_detail_and_pagination(): void
    {
        $admin = User::factory()->admin()->create();
        $pending = HelpApplication::factory()->pending()->create();
        HelpApplication::factory()->pending()->count(25)->create();
        foreach ([route('admin.help-applications.index'), route('admin.help-applications.index', ['page' => 2]), route('admin.help-applications.show', $pending->reference)] as $url) {
            $response = $this->actingAs($admin)->get($url)->assertOk();
            $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));
            $this->assertSame('no-cache', $response->headers->get('Pragma'));
        }
    }

    public function test_index_and_show_are_read_only_and_do_not_log_private_values(): void
    {
        Log::spy();
        $admin = User::factory()->admin()->create();
        $application = HelpApplication::factory()->pending()->create(['full_name' => 'LOG-PRIVATE-SENTINEL']);
        HelpApplicationDocument::factory()->acceptedUnscanned()->medicalReport()->create(['help_application_id' => $application->getKey()]);
        HelpApplicationDuplicateWarning::factory()->create(['submitted_application_id' => $application->getKey()]);
        $tables = ['users', 'help_applications', 'help_application_documents', 'help_application_duplicate_warnings',
            'audit_logs', 'internal_notification_events', 'internal_notification_event_recipients', 'internal_notifications'];
        $before = collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->get()->map(fn ($row) => (array) $row)->all()])->all();

        $this->actingAs($admin)->get(route('admin.help-applications.index'))->assertOk();
        $this->get(route('admin.help-applications.show', $application->reference))->assertOk();

        $after = collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->get()->map(fn ($row) => (array) $row)->all()])->all();
        $this->assertSame($before, $after);
        $this->assertDatabaseCount('audit_logs', 0);
        foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'] as $method) {
            Log::shouldNotHaveReceived($method);
        }
    }

    public function test_navigation_is_exact_for_eligible_and_ineligible_accounts_and_absent_publicly(): void
    {
        $url = route('admin.help-applications.index');
        foreach ([User::factory()->admin()->create(), User::factory()->superAdmin()->create()] as $actor) {
            $html = $this->actingAs($actor)->get(route('admin.dashboard'))->assertOk()->getContent();
            $this->assertSame(2, substr_count($html, $url));
            $this->assertSame(2, substr_count($html, 'Help Applications'));
            $this->assertSame(2, substr_count($html, 'طلبات المساعدة'));
        }
        $user = User::factory()->user()->create();
        $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertDontSee($url)->assertDontSee('Help Applications');
        $this->get(route('help-applications.index'))->assertOk()->assertDontSee($url);
        $this->get('/')->assertOk()->assertDontSee($url)->assertDontSee('Help Applications');
        foreach ([User::factory()->admin()->disabled()->create(), User::factory()->admin()->mustChangePassword()->create()] as $actor) {
            $this->actingAs($actor)->get(route('admin.dashboard'))->assertRedirect()->assertDontSee($url);
        }
    }

    public function test_new_required_utility_classes_exist_in_compiled_css(): void
    {
        $css = collect(glob(public_path('build/assets/*.css')))->map(fn ($path) => file_get_contents($path))->implode("\n");
        foreach (['text-indigo-600', 'hover\\:text-indigo-800', 'whitespace-pre-wrap', 'break-all', 'overflow-x-auto', 'sr-only'] as $class) {
            $this->assertStringContainsString('.'.$class, $css);
        }
    }
}
