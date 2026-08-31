<?php

namespace Tests\Feature\HelpApplication;

use App\Enums\HelpApplicationDocumentPurpose;
use App\Enums\HelpApplicationDocumentSecurityStatus;
use App\Enums\HelpApplicationDocumentUploaderKind;
use App\Models\Campaign;
use App\Models\HelpApplication;
use App\Models\HelpApplicationDocument;
use App\Models\User;
use App\Services\HelpApplicationDocumentPath;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use ReflectionClass;
use Tests\TestCase;

class HelpApplicationDocumentDataFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_has_exact_columns_indexes_and_foreign_keys(): void
    {
        $this->assertSame([
            'id', 'reference', 'help_application_id', 'storage_path', 'original_name',
            'extension', 'mime_type', 'size_bytes', 'checksum', 'checksum_algorithm',
            'purpose', 'uploader_kind', 'uploaded_by', 'security_status', 'scanned_at',
            'removed_at', 'removed_by', 'created_at', 'updated_at',
        ], Schema::getColumnListing('help_application_documents'));

        $indexes = collect(DB::select("PRAGMA index_list('help_application_documents')"))->pluck('name');
        foreach ([
            'help_application_documents_reference_unique',
            'help_application_documents_storage_path_unique',
            'help_application_documents_active_security_index',
            'help_application_documents_upload_order_index',
            'help_application_documents_uploaded_by_index',
            'help_application_documents_removed_by_index',
        ] as $index) {
            $this->assertContains($index, $indexes);
        }
        $this->assertStringContainsString(
            "string('storage_path', 191)->unique()",
            file_get_contents(database_path('migrations/2026_08_31_000000_create_help_application_documents_table.php')),
        );

        $foreignKeys = collect(DB::select("PRAGMA foreign_key_list('help_application_documents')"));
        $this->assertSame(['help_application_id', 'removed_by', 'uploaded_by'], $foreignKeys->pluck('from')->sort()->values()->all());
        $this->assertSame('RESTRICT', $foreignKeys->firstWhere('from', 'help_application_id')->on_delete);
        $this->assertSame('SET NULL', $foreignKeys->firstWhere('from', 'uploaded_by')->on_delete);
        $this->assertSame('SET NULL', $foreignKeys->firstWhere('from', 'removed_by')->on_delete);
        $this->assertFalse(Schema::hasColumn('help_application_documents', 'deleted_at'));
        $this->assertFalse(Schema::hasColumn('help_application_documents', 'campaign_id'));
    }

    public function test_enums_contain_exactly_the_approved_values(): void
    {
        $this->assertSame(['medical_report', 'cost_estimate', 'tuition_invoice', 'admission_letter', 'other'], array_column(HelpApplicationDocumentPurpose::cases(), 'value'));
        $this->assertSame(['pending', 'accepted_unscanned', 'clean', 'rejected'], array_column(HelpApplicationDocumentSecurityStatus::cases(), 'value'));
        $this->assertSame(['applicant', 'administrator'], array_column(HelpApplicationDocumentUploaderKind::cases(), 'value'));
    }

    public function test_factory_creates_consistent_private_metadata_without_a_file(): void
    {
        $document = HelpApplicationDocument::factory()->create();

        $this->assertTrue(Str::isUuid($document->reference));
        $this->assertSame('reference', $document->getRouteKeyName());
        $this->assertSame($document->application->applicant_id, $document->uploaded_by);
        $this->assertSame(HelpApplicationDocumentUploaderKind::Applicant, $document->uploader_kind);
        $this->assertSame(HelpApplicationDocumentSecurityStatus::Pending, $document->security_status);
        $this->assertNull($document->purpose);
        $this->assertNull($document->scanned_at);
        $this->assertNull($document->removed_at);
        $this->assertNull($document->removed_by);
        $this->assertSame('sha256', $document->checksum_algorithm);
        $this->assertTrue(HelpApplicationDocumentPath::isOwnedBy($document->storage_path, $document->application->reference, $document->reference, 'pdf'));
        $this->assertStringNotContainsString($document->original_name, $document->storage_path);
        $this->assertFileDoesNotExist(config('filesystems.disks.help_application_documents.root').DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $document->storage_path));
    }

    public function test_reference_and_storage_path_are_unique(): void
    {
        $document = HelpApplicationDocument::factory()->create();

        foreach (['reference', 'storage_path'] as $field) {
            try {
                HelpApplicationDocument::factory()->create([$field => $document->{$field}]);
                $this->fail("{$field} must be unique.");
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_application_delete_is_restricted_and_actor_deletes_null_foreign_keys_only(): void
    {
        $uploader = User::factory()->admin()->create();
        $remover = User::factory()->admin()->create();
        $document = HelpApplicationDocument::factory()->uploadedByAdministrator($uploader)->removedBy($remover)->create();

        try {
            $document->application->delete();
            $this->fail('An owning Help Application must be historically retained.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        $uploader->delete();
        $remover->delete();
        $document->refresh();
        $this->assertNull($document->uploaded_by);
        $this->assertNull($document->removed_by);
        $this->assertSame(HelpApplicationDocumentUploaderKind::Administrator, $document->uploader_kind);
    }

    public function test_model_is_fully_guarded_hidden_encrypted_and_has_no_soft_deletes(): void
    {
        $document = HelpApplicationDocument::factory()->create();
        $before = $document->getAttributes();

        try {
            $document->fill(['storage_path' => 'public/injected.pdf', 'purpose' => 'other']);
            $this->fail('Ordinary fill must fail closed.');
        } catch (MassAssignmentException) {
            $this->assertSame($before, $document->getAttributes());
        }

        foreach ((new HelpApplicationDocument)->getHidden() as $field) {
            $this->assertArrayNotHasKey($field, $document->toArray());
        }
        $raw = DB::table('help_application_documents')->where('id', $document->id)->first();
        $this->assertNotSame($document->original_name, $raw->original_name);
        $this->assertNotSame($document->checksum, $raw->checksum);
        $this->assertSame(['*'], (new HelpApplicationDocument)->getGuarded());
        $this->assertFalse((new ReflectionClass(HelpApplicationDocument::class))->hasMethod('bootSoftDeletes'));
        $this->assertFalse(method_exists(HelpApplicationDocument::class, 'campaign'));
        $this->assertFalse(method_exists(Campaign::class, 'helpApplicationDocument'));
    }

    public function test_relationships_and_application_scope_never_cross_ownership(): void
    {
        $first = HelpApplication::factory()->create();
        $second = HelpApplication::factory()->create();
        $owned = HelpApplicationDocument::factory()->count(2)->for($first, 'application')->create();
        $foreign = HelpApplicationDocument::factory()->for($second, 'application')->create();

        $this->assertSame($owned->pluck('id')->all(), $first->documents()->orderBy('id')->pluck('id')->all());
        $this->assertSame([$foreign->id], $second->documents()->pluck('id')->all());
        $this->assertSame($owned->pluck('id')->all(), HelpApplicationDocument::forApplication($first)->orderBy('id')->pluck('id')->all());
    }

    public function test_every_factory_state_is_consistent(): void
    {
        $purposes = [
            'medicalReport' => HelpApplicationDocumentPurpose::MedicalReport,
            'costEstimate' => HelpApplicationDocumentPurpose::CostEstimate,
            'tuitionInvoice' => HelpApplicationDocumentPurpose::TuitionInvoice,
            'admissionLetter' => HelpApplicationDocumentPurpose::AdmissionLetter,
            'other' => HelpApplicationDocumentPurpose::Other,
        ];
        foreach ($purposes as $state => $purpose) {
            $this->assertSame($purpose, HelpApplicationDocument::factory()->{$state}()->create()->purpose);
        }

        $accepted = HelpApplicationDocument::factory()->acceptedUnscanned()->create();
        $clean = HelpApplicationDocument::factory()->clean()->create();
        $rejected = HelpApplicationDocument::factory()->rejected()->create();
        $this->assertNull($accepted->scanned_at);
        $this->assertNotNull($clean->scanned_at);
        $this->assertNull($rejected->scanned_at);

        foreach (['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'png' => 'image/png'] as $state => $mime) {
            $document = HelpApplicationDocument::factory()->{$state}()->create();
            $this->assertSame($state, $document->extension);
            $this->assertSame($mime, $document->mime_type);
            $this->assertTrue(HelpApplicationDocumentPath::isOwnedBy($document->storage_path, $document->application->reference, $document->reference, $state));
        }
    }

    public function test_managed_path_is_exact_and_rejects_every_noncanonical_or_foreign_path(): void
    {
        $application = '550e8400-e29b-41d4-a716-446655440000';
        $document = '123e4567-e89b-42d3-a456-426614174000';
        $path = "applications/{$application}/documents/{$document}.pdf";

        $this->assertSame($path, HelpApplicationDocumentPath::make($application, $document, 'pdf'));
        $this->assertSame(100, strlen($path));
        $this->assertTrue(HelpApplicationDocumentPath::isOwnedBy($path, $application, $document, 'pdf'));

        $invalid = [
            '../'.$path, str_replace('/', '\\', $path), strtoupper($path),
            str_replace($application, '550e8400-e29b-41d4-a716-446655440001', $path),
            str_replace($document, '123e4567-e89b-42d3-a456-426614174001', $path),
            $path.'?download=1', $path.'.bak', str_replace('.pdf', '.gif', $path),
            'public/'.$path, 'campaign-images/'.$path, '/'.$path,
            str_replace('/documents/', '//documents/', $path),
        ];
        foreach ($invalid as $candidate) {
            $this->assertFalse(HelpApplicationDocumentPath::isOwnedBy($candidate, $application, $document, 'pdf'), $candidate);
        }

        $this->assertThrows(fn () => HelpApplicationDocumentPath::make($application, $document, 'gif'), InvalidArgumentException::class);
        $this->assertThrows(fn () => HelpApplicationDocumentPath::make(strtoupper($application), $document, 'pdf'), InvalidArgumentException::class);
    }

    public function test_config_and_disk_are_exactly_private_and_separate(): void
    {
        $disk = config('filesystems.disks.help_application_documents');
        $this->assertSame('local', $disk['driver']);
        $this->assertSame(storage_path('app/private/help-application-documents'), $disk['root']);
        $this->assertSame('private', $disk['visibility']);
        $this->assertTrue($disk['throw']);
        $this->assertArrayNotHasKey('url', $disk);
        $this->assertArrayNotHasKey('serve', $disk);
        $this->assertNotSame(config('filesystems.disks.public.root'), $disk['root']);
        $this->assertNotSame(config('filesystems.disks.campaign_images.root'), $disk['root']);
        $this->assertNotContains($disk['root'], config('filesystems.links'));

        $this->assertSame(10485760, config('help_application_documents.limits.max_file_bytes'));
        $this->assertSame(10, config('help_application_documents.limits.max_active_documents'));
        $this->assertSame(52428800, config('help_application_documents.limits.max_combined_active_bytes'));
        $this->assertSame([8000, 8000, 40000000, 100], [
            config('help_application_documents.limits.max_image_width'),
            config('help_application_documents.limits.max_image_height'),
            config('help_application_documents.limits.max_decoded_image_pixels'),
            config('help_application_documents.limits.max_pdf_pages'),
        ]);
        $this->assertSame([
            'jpg' => ['extension' => 'jpg', 'mime_type' => 'image/jpeg'],
            'png' => ['extension' => 'png', 'mime_type' => 'image/png'],
            'pdf' => ['extension' => 'pdf', 'mime_type' => 'application/pdf'],
        ], config('help_application_documents.formats'));
        $this->assertSame(['accepted_unscanned', 'clean'], config('help_application_documents.submission_eligible_security_statuses'));
    }

    public function test_submission_eligible_metadata_scope_has_the_exact_boundary(): void
    {
        $application = HelpApplication::factory()->create();
        $eligible = [
            HelpApplicationDocument::factory()->for($application, 'application')->other()->acceptedUnscanned()->create(),
            HelpApplicationDocument::factory()->for($application, 'application')->medicalReport()->clean()->create(),
        ];
        HelpApplicationDocument::factory()->for($application, 'application')->other()->create();
        HelpApplicationDocument::factory()->for($application, 'application')->other()->rejected()->create();
        HelpApplicationDocument::factory()->for($application, 'application')->acceptedUnscanned()->create();
        HelpApplicationDocument::factory()->for($application, 'application')->other()->acceptedUnscanned()->removedBy(User::factory()->admin()->create())->create();
        HelpApplicationDocument::factory()->other()->acceptedUnscanned()->create();

        $this->assertSame(
            collect($eligible)->pluck('id')->all(),
            HelpApplicationDocument::forApplication($application)->submissionEligibleMetadata()->inUploadOrder()->pluck('id')->all(),
        );
    }

    public function test_submission_eligibility_configuration_can_safely_narrow_or_disable_the_policy(): void
    {
        $application = HelpApplication::factory()->create();
        $accepted = HelpApplicationDocument::factory()->for($application, 'application')->other()->acceptedUnscanned()->create();
        $clean = HelpApplicationDocument::factory()->for($application, 'application')->other()->clean()->create();
        $original = config('help_application_documents.submission_eligible_security_statuses');

        try {
            config()->set('help_application_documents.submission_eligible_security_statuses', ['clean']);
            $this->assertSame([$clean->id], $this->eligibleDocumentIds($application));

            config()->set('help_application_documents.submission_eligible_security_statuses', []);
            $this->assertSame([], $this->eligibleDocumentIds($application));

            config()->set('help_application_documents.submission_eligible_security_statuses', ['clean', 'clean', 'accepted_unscanned']);
            $this->assertSame([$accepted->id, $clean->id], $this->eligibleDocumentIds($application));
        } finally {
            config()->set('help_application_documents.submission_eligible_security_statuses', $original);
        }
    }

    public function test_malformed_or_unsafe_submission_eligibility_configuration_fails_closed(): void
    {
        $application = HelpApplication::factory()->create();
        HelpApplicationDocument::factory()->for($application, 'application')->other()->acceptedUnscanned()->create();
        HelpApplicationDocument::factory()->for($application, 'application')->other()->clean()->create();
        $original = config('help_application_documents.submission_eligible_security_statuses');
        $unsafeConfigurations = [
            null,
            'clean',
            ['clean', 1],
            ['unknown'],
            ['pending'],
            ['rejected'],
            ['clean', 'pending'],
            ['accepted_unscanned', 'unknown'],
            ['status' => 'clean'],
        ];

        try {
            foreach ($unsafeConfigurations as $configuration) {
                config()->set('help_application_documents.submission_eligible_security_statuses', $configuration);
                $this->assertSame([], $this->eligibleDocumentIds($application));
            }
        } finally {
            config()->set('help_application_documents.submission_eligible_security_statuses', $original);
        }
    }

    public function test_no_document_http_surface_or_campaign_coupling_exists(): void
    {
        $this->assertFalse(collect(Route::getRoutes())->contains(fn ($route): bool => str_contains(strtolower($route->uri().' '.(string) $route->getName().' '.$route->getActionName()), 'document')));
        $this->assertFalse(Schema::hasColumn('campaigns', 'help_application_document_id'));
    }

    /** @return list<int> */
    private function eligibleDocumentIds(HelpApplication $application): array
    {
        return HelpApplicationDocument::forApplication($application)
            ->submissionEligibleMetadata()
            ->inUploadOrder()
            ->pluck('id')
            ->all();
    }
}
