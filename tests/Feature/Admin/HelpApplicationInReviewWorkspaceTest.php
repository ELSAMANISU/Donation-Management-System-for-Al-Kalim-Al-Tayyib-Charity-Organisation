<?php

namespace Tests\Feature\Admin;

use App\Enums\HelpApplicationDocumentPurpose;
use App\Enums\HelpApplicationDocumentSecurityStatus;
use App\Enums\HelpApplicationStatus;
use App\Models\HelpApplication;
use App\Models\HelpApplicationDocument;
use App\Models\HelpApplicationDuplicateWarning;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class HelpApplicationInReviewWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_exact_in_review_routes_include_two_reads_and_one_bounded_category_mutation(): void
    {
        $routes = collect(app('router')->getRoutes());
        $inReview = $routes->filter(fn ($route) => str_starts_with((string) $route->getName(), 'admin.help-applications.in-review.'))->values();

        $this->assertCount(3, $inReview);
        $this->assertSame([
            'admin.help-applications.in-review.index',
            'admin.help-applications.in-review.assign-category',
            'admin.help-applications.in-review.show',
        ], $inReview->pluck('action.as')->all());
        foreach ($inReview->whereIn('action.as', ['admin.help-applications.in-review.index', 'admin.help-applications.in-review.show']) as $route) {
            $this->assertSame(['GET', 'HEAD'], $route->methods());
            $this->assertSame(['web', 'auth', 'role:admin,super_admin'], $route->gatherMiddleware());
        }
        $this->assertSame('admin/help-applications/in-review', $inReview[0]->uri());
        $this->assertSame('admin/help-applications/in-review/{helpApplication}/assign-category', $inReview[1]->uri());
        $this->assertSame('admin/help-applications/in-review/{helpApplication}', $inReview[2]->uri());
        $this->assertSame('[\\da-fA-F]{8}-[\\da-fA-F]{4}-[\\da-fA-F]{4}-[\\da-fA-F]{4}-[\\da-fA-F]{12}', $inReview[2]->wheres['helpApplication']);
        $this->assertLessThan(
            $routes->search(fn ($route) => $route->getName() === 'admin.help-applications.show'),
            $routes->search(fn ($route) => $route->getName() === 'admin.help-applications.in-review.index'),
        );
        $this->assertSame(['POST'], $inReview[1]->methods());
    }

    public function test_authentication_role_disabled_and_password_change_boundaries_are_preserved(): void
    {
        $url = route('admin.help-applications.in-review.index');
        $this->get($url)->assertRedirect(route('login'));
        $this->actingAs(User::factory()->user()->create())->get($url)->assertForbidden();
        $this->actingAs(User::factory()->admin()->disabled()->create())->get($url)->assertRedirect(route('login'));
        $this->actingAs(User::factory()->admin()->mustChangePassword()->create())->get($url)
            ->assertRedirect(route('password.change.required.edit'));
    }

    public function test_admin_queue_is_assignment_scoped_and_super_admin_queue_includes_all_and_orphans(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Assigned Reviewer']);
        $other = User::factory()->admin()->create(['name' => 'Other Reviewer']);
        $mine = HelpApplication::factory()->underReview()->create(['reviewed_by' => $admin->id, 'full_name' => 'Mine']);
        $theirs = HelpApplication::factory()->underReview()->create(['reviewed_by' => $other->id, 'full_name' => 'Theirs']);
        $orphan = HelpApplication::factory()->underReview()->create(['reviewed_by' => null, 'full_name' => 'Orphan']);
        HelpApplication::factory()->pending()->create(['full_name' => 'Pending']);

        $this->actingAs($admin)->get(route('admin.help-applications.in-review.index'))
            ->assertOk()->assertSee($mine->reference)->assertDontSee($theirs->reference)
            ->assertDontSee($orphan->reference)->assertDontSee('Other Reviewer');
        $this->actingAs(User::factory()->superAdmin()->create())->get(route('admin.help-applications.in-review.index'))
            ->assertOk()->assertSee($mine->reference)->assertSee($theirs->reference)->assertSee($orphan->reference)
            ->assertSee('Assigned Reviewer')->assertSee('Reviewer unavailable / المسؤول غير متاح')
            ->assertDontSee('Pending');
    }

    public function test_queue_orders_and_paginates_exactly_twenty_five(): void
    {
        $admin = User::factory()->admin()->create();
        for ($i = 1; $i <= 26; $i++) {
            HelpApplication::factory()->underReview()->create([
                'reviewed_by' => $admin->id,
                'full_name' => "Applicant {$i}",
                'review_started_at' => now()->addMinutes($i),
            ]);
        }

        $first = $this->actingAs($admin)->get(route('admin.help-applications.in-review.index', ['ignored' => 'value']))->assertOk();
        $first->assertSeeInOrder(['Applicant 26', 'Applicant 25'])->assertDontSee('>Applicant 1<', false);
        $this->get(route('admin.help-applications.in-review.index', ['page' => 2]))->assertOk()->assertSee('>Applicant 1<', false)->assertDontSee('>Applicant 2<', false);
    }

    public function test_detail_conceals_wrong_assignment_or_status_and_all_invalid_references(): void
    {
        $admin = User::factory()->admin()->create();
        $other = User::factory()->admin()->create();
        $mine = HelpApplication::factory()->underReview()->create(['reviewed_by' => $admin->id]);
        $theirs = HelpApplication::factory()->underReview()->create(['reviewed_by' => $other->id]);
        $orphan = HelpApplication::factory()->underReview()->create(['reviewed_by' => null]);

        $this->actingAs($admin)->get(route('admin.help-applications.in-review.show', $mine->reference))->assertOk();
        foreach ([$theirs->reference, $orphan->reference] as $reference) {
            $this->get(route('admin.help-applications.in-review.show', $reference))->assertNotFound();
        }
        foreach (HelpApplicationStatus::cases() as $status) {
            if ($status === HelpApplicationStatus::UnderReview) {
                continue;
            }
            $application = HelpApplication::factory()->create(['status' => $status]);
            $this->get(route('admin.help-applications.in-review.show', $application->reference))->assertNotFound();
        }
        foreach (['123', 'malformed', (string) Str::uuid()] as $reference) {
            $this->get('/admin/help-applications/in-review/'.$reference)->assertNotFound();
        }
    }

    public function test_super_admin_detail_supports_deleted_reviewer_without_storage_or_mutation_controls(): void
    {
        Storage::shouldReceive('disk')->never();
        Storage::shouldReceive('exists')->never();
        Storage::shouldReceive('get')->never();
        $reviewer = User::factory()->admin()->create(['name' => 'Reviewer Name']);
        $application = HelpApplication::factory()->underReview()->create(['reviewed_by' => $reviewer->id]);
        HelpApplicationDocument::factory()->for($application, 'application')->acceptedUnscanned()->medicalReport()->create();
        $reviewer->delete();

        $response = $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('admin.help-applications.in-review.show', $application->reference));

        $response->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Pragma', 'no-cache')
            ->assertSee('Reviewer unavailable / المسؤول غير متاح')->assertSee('synthetic-supporting-document.pdf')
            ->assertDontSee('Download')->assertDontSee('Preview');
    }

    public function test_normal_detail_shows_approved_fields_only_and_escapes_values(): void
    {
        $admin = User::factory()->admin()->create();
        $application = HelpApplication::factory()->underReview()->create([
            'reviewed_by' => $admin->id,
            'full_name' => '<script>alert(1)</script>',
            'private_story' => "Line one\nLine two",
            'identity_document_number' => 'SECRET-IDENTITY',
            'identity_blind_index' => 'SECRET-BLIND',
        ]);

        $response = $this->actingAs($admin)->get(route('admin.help-applications.in-review.show', $application->reference));
        $response->assertOk()->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertSee("Line one\nLine two")->assertDontSee('SECRET-IDENTITY')->assertDontSee('SECRET-BLIND')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Pragma', 'no-cache');
    }

    public function test_secondary_queries_reassert_status_and_assignment_after_a_deterministic_stale_read(): void
    {
        $reviewer = User::factory()->admin()->create(['name' => 'STALE REVIEWER']);
        $application = HelpApplication::factory()->underReview()->create(['reviewed_by' => $reviewer->id]);
        HelpApplicationDocument::factory()->for($application, 'application')->acceptedUnscanned()->medicalReport()->create([
            'original_name' => 'STALE DOCUMENT',
        ]);
        HelpApplicationDuplicateWarning::factory()->create([
            'submitted_application_id' => $application->getKey(),
            'resolution_note' => 'STALE WARNING PRIVATE NOTE',
        ]);
        $changed = false;
        DB::listen(function ($query) use ($application, &$changed): void {
            if (! $changed && str_contains($query->sql, 'from "help_applications"') && str_contains($query->sql, '"full_name"')) {
                $changed = true;
                DB::table('help_applications')->where('reference', $application->reference)->update([
                    'status' => HelpApplicationStatus::Pending->value,
                    'reviewed_by' => null,
                ]);
            }
        });

        $response = $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('admin.help-applications.in-review.show', $application->reference));

        $response->assertOk()->assertDontSee('STALE DOCUMENT')->assertDontSee('STALE REVIEWER')
            ->assertDontSee('STALE WARNING PRIVATE NOTE')->assertSee('No active supporting-document metadata.')
            ->assertSee('Reviewer unavailable / المسؤول غير متاح')->assertSee('عدد تحذيرات التكرار</span>: 0', false);
        $this->assertTrue($changed);
    }

    public function test_normal_admin_queue_has_exact_private_headers_and_bilingual_empty_state(): void
    {
        $response = $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.help-applications.in-review.index'));

        $response->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Pragma', 'no-cache')
            ->assertSee('No help applications are currently under review.')
            ->assertSee('لا توجد طلبات مساعدة قيد المراجعة حاليًا.');
    }

    public function test_super_admin_queue_has_exact_private_headers(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('admin.help-applications.in-review.index'))
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Pragma', 'no-cache');
    }

    public function test_queue_projection_and_output_contain_only_approved_application_fields(): void
    {
        $admin = User::factory()->admin()->create();
        $application = HelpApplication::factory()->underReview()->create([
            'reviewed_by' => $admin->id,
            'full_name' => 'QUEUE APPROVED NAME',
            'email' => 'queue-private-email@example.test',
            'phone' => 'QUEUE-PRIVATE-PHONE',
            'address' => 'QUEUE-PRIVATE-ADDRESS',
            'date_of_birth' => '1988-02-03',
            'requested_amount' => '9876543.21',
            'private_story' => 'QUEUE-PRIVATE-STORY',
            'preferred_receiving_method' => 'QUEUE-PRIVATE-RECEIVING',
            'identity_document_number' => 'QUEUE-PRIVATE-IDENTITY',
            'identity_blind_index' => 'QUEUE-PRIVATE-BLIND',
            'identity_blind_index_version' => 77,
            'consent_version' => 'QUEUE-PRIVATE-CONSENT',
        ]);
        HelpApplicationDocument::factory()->for($application, 'application')->create(['original_name' => 'QUEUE-PRIVATE-DOCUMENT']);
        $warning = HelpApplicationDuplicateWarning::factory()->create([
            'submitted_application_id' => $application->id,
            'resolution_note' => 'QUEUE-PRIVATE-WARNING',
        ]);

        $response = $this->actingAs($admin)->get(route('admin.help-applications.in-review.index'));
        $projected = $response->viewData('applications')->first();
        $this->assertSame(['reference', 'status', 'full_name', 'submitted_at', 'review_started_at'], array_keys($projected->getAttributes()));
        $response->assertSee('QUEUE APPROVED NAME')->assertSee($application->reference)
            ->assertSee($application->submitted_at->format('Y-m-d H:i'))->assertSee($application->review_started_at->format('Y-m-d H:i'))
            ->assertSee('Under review /')->assertDontSee('queue-private-email@example.test')
            ->assertDontSee('QUEUE-PRIVATE-PHONE')->assertDontSee('QUEUE-PRIVATE-ADDRESS')->assertDontSee('1988-02-03')
            ->assertDontSee('9,876,543.21')->assertDontSee('QUEUE-PRIVATE-STORY')->assertDontSee('QUEUE-PRIVATE-RECEIVING')
            ->assertDontSee('QUEUE-PRIVATE-IDENTITY')->assertDontSee('QUEUE-PRIVATE-BLIND')->assertDontSee('QUEUE-PRIVATE-CONSENT')
            ->assertDontSee('QUEUE-PRIVATE-DOCUMENT')->assertDontSee('QUEUE-PRIVATE-WARNING')->assertDontSee($warning->reference);
    }

    public function test_normal_queue_hides_reviewer_name_while_super_admin_queue_shows_it(): void
    {
        $reviewer = User::factory()->admin()->create(['name' => 'UNIQUE AVAILABLE REVIEWER']);
        HelpApplication::factory()->underReview()->create(['reviewed_by' => $reviewer->id]);

        $normalHtml = $this->actingAs($reviewer)->get(route('admin.help-applications.in-review.index'))->getContent();
        $table = preg_replace('/.*(<table.*<\/table>).*/s', '$1', $normalHtml);
        $this->assertStringNotContainsString('UNIQUE AVAILABLE REVIEWER', $table);
        $this->actingAs(User::factory()->superAdmin()->create())->get(route('admin.help-applications.in-review.index'))
            ->assertSee('UNIQUE AVAILABLE REVIEWER');
    }

    public function test_super_admin_queue_uses_exact_unavailable_reviewer_label_for_orphan(): void
    {
        HelpApplication::factory()->underReview()->create(['reviewed_by' => null]);
        $this->actingAs(User::factory()->superAdmin()->create())->get(route('admin.help-applications.in-review.index'))
            ->assertSee('Reviewer unavailable / المسؤول غير متاح');
    }

    public function test_queue_excludes_every_non_under_review_status(): void
    {
        $admin = User::factory()->admin()->create();
        foreach (HelpApplicationStatus::cases() as $status) {
            HelpApplication::factory()->create([
                'status' => $status,
                'reviewed_by' => $admin->id,
                'full_name' => 'STATUS-'.$status->value,
            ]);
        }

        $response = $this->actingAs($admin)->get(route('admin.help-applications.in-review.index'));
        $response->assertSee('STATUS-under_review');
        foreach (HelpApplicationStatus::cases() as $status) {
            if ($status !== HelpApplicationStatus::UnderReview) {
                $response->assertDontSee('STATUS-'.$status->value);
            }
        }
    }

    public function test_queue_uses_review_started_descending_then_id_descending_order(): void
    {
        $admin = User::factory()->admin()->create();
        $older = HelpApplication::factory()->underReview()->create(['reviewed_by' => $admin->id, 'full_name' => 'ORDER OLDER', 'review_started_at' => now()->subMinute()]);
        $tieLower = HelpApplication::factory()->underReview()->create(['reviewed_by' => $admin->id, 'full_name' => 'ORDER TIE LOWER', 'review_started_at' => now()]);
        $tieHigher = HelpApplication::factory()->underReview()->create(['reviewed_by' => $admin->id, 'full_name' => 'ORDER TIE HIGHER', 'review_started_at' => $tieLower->review_started_at]);

        $this->actingAs($admin)->get(route('admin.help-applications.in-review.index'))
            ->assertSeeInOrder([$tieHigher->reference, $tieLower->reference, $older->reference]);
    }

    public function test_unrelated_queue_parameters_do_not_filter_reorder_or_expand_scope(): void
    {
        $admin = User::factory()->admin()->create();
        $other = User::factory()->admin()->create();
        $mine = HelpApplication::factory()->underReview()->create(['reviewed_by' => $admin->id, 'full_name' => 'PARAM MINE']);
        $theirs = HelpApplication::factory()->underReview()->create(['reviewed_by' => $other->id, 'full_name' => 'PARAM THEIRS']);

        $this->actingAs($admin)->get(route('admin.help-applications.in-review.index', [
            'status' => 'pending', 'reviewed_by' => $other->id, 'sort' => 'full_name', 'search' => 'THEIRS',
        ]))->assertOk()->assertSee($mine->reference)->assertDontSee($theirs->reference);
    }

    public function test_normal_admin_detail_scope_conceals_foreign_and_orphaned_applications(): void
    {
        $admin = User::factory()->admin()->create();
        $other = User::factory()->admin()->create();
        $mine = HelpApplication::factory()->underReview()->create(['reviewed_by' => $admin->id]);
        $foreign = HelpApplication::factory()->underReview()->create(['reviewed_by' => $other->id]);
        $orphan = HelpApplication::factory()->underReview()->create(['reviewed_by' => null]);

        $this->actingAs($admin)->get(route('admin.help-applications.in-review.show', $mine->reference))->assertOk();
        $this->get(route('admin.help-applications.in-review.show', $foreign->reference))->assertNotFound();
        $this->get(route('admin.help-applications.in-review.show', $orphan->reference))->assertNotFound();
    }

    public function test_super_admin_detail_opens_assigned_and_orphaned_applications_with_private_headers(): void
    {
        $reviewer = User::factory()->admin()->create();
        $assigned = HelpApplication::factory()->underReview()->create(['reviewed_by' => $reviewer->id]);
        $orphan = HelpApplication::factory()->underReview()->create(['reviewed_by' => null]);
        $superAdmin = User::factory()->superAdmin()->create();

        foreach ([$assigned, $orphan] as $application) {
            $this->actingAs($superAdmin)->get(route('admin.help-applications.in-review.show', $application->reference))
                ->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Pragma', 'no-cache');
        }
    }

    public function test_detail_displays_all_approved_fields_and_bilingual_labels(): void
    {
        $admin = User::factory()->admin()->create();
        $application = HelpApplication::factory()->underReview()->create([
            'reviewed_by' => $admin->id,
            'full_name' => 'APPROVED FULL NAME', 'email' => 'approved@example.test', 'phone' => 'APPROVED PHONE',
            'address' => 'APPROVED ADDRESS', 'date_of_birth' => '1991-04-05', 'requested_amount' => '4321.09',
            'private_story' => "APPROVED STORY ONE\nAPPROVED STORY TWO",
            'preferred_receiving_method' => "APPROVED METHOD ONE\nAPPROVED METHOD TWO",
            'identity_issuing_country' => 'sd',
        ]);

        $response = $this->actingAs($admin)->get(route('admin.help-applications.in-review.show', $application->reference));
        foreach ([$application->reference, 'Under review', 'قيد المراجعة', 'Submitted', 'تاريخ التقديم', 'Review started',
            'تاريخ بدء المراجعة', 'APPROVED FULL NAME', 'approved@example.test', 'APPROVED PHONE', 'APPROVED ADDRESS',
            '1991-04-05', '4,321.09 SDG', 'APPROVED STORY ONE', 'APPROVED STORY TWO', 'APPROVED METHOD ONE',
            'APPROVED METHOD TWO', 'Public identity preference', 'تفضيل الهوية العلنية', 'National ID', 'بطاقة قومية',
            'Issuing country', 'بلد الإصدار', 'SD'] as $visible) {
            $response->assertSee($visible);
        }
    }

    public function test_detail_escapes_stored_html_in_every_rendered_string_field(): void
    {
        $admin = User::factory()->admin()->create();
        $payload = '<script>UNESCAPED-XSS</script>';
        $application = HelpApplication::factory()->underReview()->create([
            'reviewed_by' => $admin->id, 'full_name' => $payload, 'email' => 'xss@example.test',
            'phone' => $payload, 'address' => $payload, 'private_story' => $payload,
            'preferred_receiving_method' => $payload,
        ]);
        HelpApplicationDocument::factory()->for($application, 'application')->acceptedUnscanned()->medicalReport()->create(['original_name' => $payload.'.pdf']);

        $response = $this->actingAs($admin)->get(route('admin.help-applications.in-review.show', $application->reference));
        $response->assertSee('&lt;script&gt;UNESCAPED-XSS&lt;/script&gt;', false)->assertDontSee($payload, false);
    }

    public function test_corrupt_identity_ciphertext_is_never_accessed_or_decrypted(): void
    {
        $admin = User::factory()->admin()->create();
        $application = HelpApplication::factory()->underReview()->create(['reviewed_by' => $admin->id]);
        DB::table('help_applications')->where('id', $application->id)->update(['identity_document_number' => 'not-valid-ciphertext']);

        $this->actingAs($admin)->get(route('admin.help-applications.in-review.show', $application->reference))->assertOk();
    }

    public function test_detail_excludes_application_reviewer_and_workflow_internals(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'NORMAL REVIEWER PRIVATE NAME']);
        $application = HelpApplication::factory()->underReview()->create([
            'reviewed_by' => $admin->id, 'identity_document_number' => 'DETAIL SECRET IDENTITY',
            'identity_blind_index' => 'DETAIL SECRET BLIND', 'identity_blind_index_version' => 91,
            'consent_version' => 'DETAIL SECRET CONSENT', 'category_assigned_by' => $admin->id,
            'decided_by' => $admin->id, 'updated_by' => $admin->id,
        ]);

        $html = $this->actingAs($admin)->get(route('admin.help-applications.in-review.show', $application->reference))->getContent();
        $main = preg_replace('/.*<main>(.*)<\/main>.*/s', '$1', $html);
        foreach (['DETAIL SECRET IDENTITY', 'DETAIL SECRET BLIND', 'DETAIL SECRET CONSENT', 'NORMAL REVIEWER PRIVATE NAME',
            'category_assigned_by', 'decided_by', 'updated_by', 'appeal_eligibility_ended_at'] as $private) {
            $this->assertStringNotContainsString($private, $main);
        }
    }

    public function test_super_admin_detail_shows_available_reviewer_name(): void
    {
        $reviewer = User::factory()->admin()->create(['name' => 'DETAIL AVAILABLE REVIEWER']);
        $application = HelpApplication::factory()->underReview()->create(['reviewed_by' => $reviewer->id]);
        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('admin.help-applications.in-review.show', $application->reference))
            ->assertSee('DETAIL AVAILABLE REVIEWER');
    }

    public function test_all_supported_document_formats_purposes_and_security_statuses_render_safe_labels(): void
    {
        $admin = User::factory()->admin()->create();
        $application = HelpApplication::factory()->underReview()->create(['reviewed_by' => $admin->id]);
        $formats = [['pdf', 'PDF'], ['jpg', 'JPEG'], ['png', 'PNG']];
        foreach ($formats as [$state, $label]) {
            HelpApplicationDocument::factory()->for($application, 'application')->{$state}()->medicalReport()->create(['original_name' => "FORMAT-{$state}.{$state}"]);
        }
        foreach (HelpApplicationDocumentPurpose::cases() as $purpose) {
            HelpApplicationDocument::factory()->for($application, 'application')->create(['purpose' => $purpose, 'original_name' => 'PURPOSE-'.$purpose->value]);
        }
        foreach (HelpApplicationDocumentSecurityStatus::cases() as $status) {
            HelpApplicationDocument::factory()->for($application, 'application')->medicalReport()->create(['security_status' => $status, 'original_name' => 'SECURITY-'.$status->value]);
        }

        $response = $this->actingAs($admin)->get(route('admin.help-applications.in-review.show', $application->reference));
        foreach (['PDF', 'JPEG', 'PNG', 'Medical report', 'Cost estimate', 'Tuition invoice', 'Admission letter', 'Other evidence',
            'Processing', 'Structurally accepted; not malware-scanned', 'Malware scan completed', 'Not accepted'] as $label) {
            $response->assertSee($label);
        }
    }

    public function test_document_query_excludes_removed_and_foreign_documents(): void
    {
        $admin = User::factory()->admin()->create();
        $application = HelpApplication::factory()->underReview()->create(['reviewed_by' => $admin->id]);
        HelpApplicationDocument::factory()->for($application, 'application')->medicalReport()->create(['original_name' => 'ACTIVE DOCUMENT']);
        HelpApplicationDocument::factory()->for($application, 'application')->medicalReport()->removedBy($admin)->create(['original_name' => 'REMOVED DOCUMENT']);
        HelpApplicationDocument::factory()->medicalReport()->create(['original_name' => 'FOREIGN DOCUMENT']);

        $this->actingAs($admin)->get(route('admin.help-applications.in-review.show', $application->reference))
            ->assertSee('ACTIVE DOCUMENT')->assertDontSee('REMOVED DOCUMENT')->assertDontSee('FOREIGN DOCUMENT');
    }

    public function test_document_output_contains_only_approved_metadata_and_never_storage_or_identifier_data(): void
    {
        Storage::shouldReceive('disk')->never();
        Storage::shouldReceive('exists')->never();
        Storage::shouldReceive('get')->never();
        Storage::shouldReceive('readStream')->never();
        Storage::shouldReceive('download')->never();
        Storage::shouldReceive('url')->never();
        Storage::shouldReceive('temporaryUrl')->never();
        $admin = User::factory()->admin()->create();
        $application = HelpApplication::factory()->underReview()->create(['reviewed_by' => $admin->id]);
        $document = HelpApplicationDocument::factory()->for($application, 'application')->acceptedUnscanned()->medicalReport()->create([
            'original_name' => 'APPROVED-FILENAME.pdf', 'size_bytes' => 24680,
            'storage_path' => 'PRIVATE-STORAGE-PATH', 'checksum' => 'PRIVATE-CHECKSUM',
        ]);

        $response = $this->actingAs($admin)->get(route('admin.help-applications.in-review.show', $application->reference));
        $response->assertSee('APPROVED-FILENAME.pdf')->assertSee('PDF')->assertSee('24,680 bytes')
            ->assertSee('Medical report')->assertSee('Structurally accepted; not malware-scanned')
            ->assertSee($document->created_at->format('Y-m-d H:i'))->assertDontSee('PRIVATE-STORAGE-PATH')
            ->assertDontSee('PRIVATE-CHECKSUM')->assertDontSee($document->reference)->assertDontSee('download', false)
            ->assertDontSee('preview', false)->assertDontSee('data:')->assertDontSee('blob:');
    }

    public function test_duplicate_warning_counts_zero_one_and_multiple_exactly(): void
    {
        $admin = User::factory()->admin()->create();
        foreach ([0, 1, 3] as $count) {
            $application = HelpApplication::factory()->underReview()->create(['reviewed_by' => $admin->id]);
            HelpApplicationDuplicateWarning::factory()->count($count)->create(['submitted_application_id' => $application->id]);
            $this->actingAs($admin)->get(route('admin.help-applications.in-review.show', $application->reference))
                ->assertSee('عدد تحذيرات التكرار</span>: '.$count, false);
        }
    }

    public function test_duplicate_warning_output_never_exposes_warning_or_matched_application_private_data(): void
    {
        $admin = User::factory()->admin()->create();
        $application = HelpApplication::factory()->underReview()->create(['reviewed_by' => $admin->id]);
        $matched = HelpApplication::factory()->closed()->create([
            'full_name' => 'MATCHED PRIVATE NAME', 'identity_document_number' => 'MATCHED PRIVATE IDENTITY',
        ]);
        $warning = HelpApplicationDuplicateWarning::factory()->confirmed()->create([
            'submitted_application_id' => $application->id, 'matched_application_id' => $matched->id,
            'resolution_note' => 'WARNING PRIVATE RESOLUTION',
        ]);

        $response = $this->actingAs($admin)->get(route('admin.help-applications.in-review.show', $application->reference));
        $response->assertSee('عدد تحذيرات التكرار</span>: 1', false);
        foreach ([$matched->reference, 'MATCHED PRIVATE NAME', 'MATCHED PRIVATE IDENTITY', $warning->reference,
            'WARNING PRIVATE RESOLUTION', 'confirmed_match'] as $private) {
            $response->assertDontSee($private);
        }
    }

    public function test_queue_access_leaves_complete_relevant_database_snapshots_unchanged(): void
    {
        $admin = User::factory()->admin()->create();
        $application = HelpApplication::factory()->underReview()->create(['reviewed_by' => $admin->id]);
        HelpApplicationDocument::factory()->for($application, 'application')->medicalReport()->create();
        HelpApplicationDuplicateWarning::factory()->create(['submitted_application_id' => $application->id]);
        $before = $this->relevantDatabaseSnapshot();

        $this->actingAs($admin)->get(route('admin.help-applications.in-review.index'))->assertOk();

        $this->assertSame($before, $this->relevantDatabaseSnapshot());
    }

    public function test_detail_access_leaves_complete_relevant_database_snapshots_unchanged(): void
    {
        $admin = User::factory()->admin()->create();
        $application = HelpApplication::factory()->underReview()->create(['reviewed_by' => $admin->id]);
        HelpApplicationDocument::factory()->for($application, 'application')->medicalReport()->create();
        HelpApplicationDuplicateWarning::factory()->create(['submitted_application_id' => $application->id]);
        $before = $this->relevantDatabaseSnapshot();

        $this->actingAs($admin)->get(route('admin.help-applications.in-review.show', $application->reference))->assertOk();

        $this->assertSame($before, $this->relevantDatabaseSnapshot());
    }

    public function test_queue_and_detail_views_create_no_audit_or_notification_rows(): void
    {
        $admin = User::factory()->admin()->create();
        $application = HelpApplication::factory()->underReview()->create(['reviewed_by' => $admin->id]);
        $tables = ['audit_logs', 'internal_notification_events', 'internal_notification_event_recipients', 'internal_notifications'];
        $before = collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()])->all();

        $this->actingAs($admin)->get(route('admin.help-applications.in-review.index'))->assertOk();
        $this->get(route('admin.help-applications.in-review.show', $application->reference))->assertOk();

        foreach ($before as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), $table.' changed during a read-only view.');
        }
    }

    public function test_queue_and_detail_views_produce_no_application_log_activity(): void
    {
        Log::spy();
        $admin = User::factory()->admin()->create();
        $application = HelpApplication::factory()->underReview()->create(['reviewed_by' => $admin->id]);
        $this->actingAs($admin)->get(route('admin.help-applications.in-review.index'))->assertOk();
        $this->get(route('admin.help-applications.in-review.show', $application->reference))->assertOk();
        foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'] as $method) {
            Log::shouldNotHaveReceived($method);
        }
    }

    public function test_queue_and_detail_views_dispatch_no_notification_mail_job_event_or_storage_behavior(): void
    {
        Notification::fake();
        Mail::fake();
        Queue::fake();
        Bus::fake();
        Storage::shouldReceive('disk')->never();
        Storage::shouldReceive('exists')->never();
        Storage::shouldReceive('get')->never();
        Storage::shouldReceive('readStream')->never();
        Storage::shouldReceive('download')->never();
        Storage::shouldReceive('url')->never();
        Storage::shouldReceive('temporaryUrl')->never();
        $admin = User::factory()->admin()->create();
        $application = HelpApplication::factory()->underReview()->create(['reviewed_by' => $admin->id]);

        $this->actingAs($admin)->get(route('admin.help-applications.in-review.index'))->assertOk();
        $this->get(route('admin.help-applications.in-review.show', $application->reference))->assertOk();

        Notification::assertNothingSent();
        Mail::assertNothingSent();
        Queue::assertNothingPushed();
        Bus::assertNothingDispatched();
    }

    public function test_desktop_and_responsive_navigation_show_one_link_each_only_to_eligible_administrators(): void
    {
        $url = route('admin.help-applications.in-review.index');
        foreach ([User::factory()->admin()->create(), User::factory()->superAdmin()->create()] as $actor) {
            $html = $this->actingAs($actor)->get(route('admin.dashboard'))->assertOk()->getContent();
            $this->assertSame(2, substr_count($html, 'href="'.$url.'"'));
            $this->assertSame(2, substr_count($html, 'In-review Applications'));
        }
        foreach ([User::factory()->user()->create(), User::factory()->admin()->disabled()->create(), User::factory()->admin()->mustChangePassword()->create()] as $actor) {
            $response = $this->actingAs($actor)->get(route('dashboard'));
            if ($response->isOk()) {
                $response->assertDontSee($url);
            } else {
                $response->assertRedirect()->assertDontSee($url);
            }
        }
        $this->app['auth']->forgetGuards();
        $this->get('/')->assertOk()->assertDontSee($url);
    }

    public function test_pending_and_in_review_navigation_active_states_are_independent(): void
    {
        $admin = User::factory()->admin()->create();
        $pendingUrl = route('admin.help-applications.index');
        $inReviewUrl = route('admin.help-applications.in-review.index');

        $pendingHtml = $this->actingAs($admin)->get($pendingUrl)->assertOk()->getContent();
        $this->assertLinkActive($pendingHtml, $pendingUrl, true);
        $this->assertLinkActive($pendingHtml, $inReviewUrl, false);

        $inReviewHtml = $this->get($inReviewUrl)->assertOk()->getContent();
        $this->assertLinkActive($inReviewHtml, $pendingUrl, false);
        $this->assertLinkActive($inReviewHtml, $inReviewUrl, true);
    }

    public function test_literal_in_review_route_matches_dedicated_controller_not_pending_uuid_binding(): void
    {
        $this->actingAs(User::factory()->admin()->create())->get('/admin/help-applications/in-review')
            ->assertOk()->assertViewIs('admin.help-applications.in-review.index');
    }

    public function test_detail_template_contains_only_the_bounded_category_assignment_workflow_form(): void
    {
        $source = file_get_contents(resource_path('views/admin/help-applications/in-review/show.blade.php'));
        $this->assertSame(1, substr_count($source, '<form'));
        $this->assertSame(1, substr_count($source, '<button'));
        foreach (['start-review', 'categories.store', 'categories.update', 'reviewer reassignment',
            'request-information', 'applications.approve', 'applications.reject', 'warnings.resolve',
            'documents.store', 'documents.destroy', 'documents.download', 'documents.preview'] as $prohibited) {
            $this->assertStringNotContainsStringIgnoringCase($prohibited, $source);
        }
    }

    public function test_every_tailwind_utility_used_by_new_views_exists_in_compiled_css(): void
    {
        $css = collect(glob(public_path('build/assets/*.css')))->map(fn ($path) => file_get_contents($path))->implode("\n");
        $classes = ['text-xl', 'font-semibold', 'text-gray-800', 'py-12', 'max-w-7xl', 'overflow-hidden',
            'rounded-lg', 'bg-white', 'shadow-sm', 'overflow-x-auto', 'min-w-full', 'divide-y', 'sr-only',
            'text-xs', 'uppercase', 'text-gray-500', 'font-mono', 'text-indigo-600', 'hover:text-indigo-800',
            'space-y-6', 'text-lg', 'grid', 'gap-4', 'sm:grid-cols-2', 'break-all', 'space-y-4',
            'whitespace-pre-wrap'];
        foreach ($classes as $class) {
            $selector = '.'.str_replace([':', '/'], ['\\:', '\\/'], $class);
            $this->assertStringContainsString($selector, $css, "Missing compiled Tailwind utility: {$class}");
        }
    }

    /** @return array<string, array<int, object>> */
    private function relevantDatabaseSnapshot(): array
    {
        return collect([
            'help_applications', 'help_application_documents', 'help_application_duplicate_warnings', 'audit_logs',
            'internal_notification_events', 'internal_notification_event_recipients', 'internal_notifications',
        ])->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()
            ->map(fn ($row) => (array) $row)->all()])->all();
    }

    private function assertLinkActive(string $html, string $url, bool $expectedActive): void
    {
        preg_match_all('/<a class="([^"]+)" href="'.preg_quote($url, '/').'">/', $html, $matches);
        $this->assertCount(2, $matches[1]);
        foreach ($matches[1] as $classes) {
            $isActive = str_contains($classes, 'border-indigo-400');
            $this->assertSame($expectedActive, $isActive, "Unexpected active state for {$url}");
        }
    }
}
