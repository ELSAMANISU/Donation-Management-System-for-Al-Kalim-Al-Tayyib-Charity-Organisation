<?php

namespace Tests\Feature\HelpApplication;

use App\Enums\HelpApplicationDocumentPurpose;
use App\Enums\HelpApplicationStatus;
use App\Models\AuditLog;
use App\Models\HelpApplication;
use App\Models\HelpApplicationDocument;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\HelpApplicationDocumentInspector;
use App\Services\HelpApplicationDocumentService;
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
use LogicException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class HelpApplicationDocumentDraftWorkflowTest extends TestCase
{
    use DatabaseMigrations;

    public function runDatabaseMigrations(): void
    {
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->artisan('migrate:fresh');
        $this->app[Kernel::class]->setArtisan(null);
    }

    public function test_owner_can_upload_private_png_and_logically_remove_it(): void
    {
        Storage::fake('help_application_documents');
        $actor = User::factory()->create();
        $application = HelpApplication::factory()->for($actor, 'applicant')->create([
            'status' => HelpApplicationStatus::Draft,
            'open_slot' => true,
        ]);

        $upload = UploadedFile::fake()->image('PRIVATE-FILENAME-SENTINEL.png', 20, 20);
        $acceptedBytes = file_get_contents($upload->getRealPath());
        $response = $this->actingAs($actor)->post(route('help-applications.documents.store', $application), [
            'purpose' => 'medical_report',
            'document' => $upload,
        ]);

        $response->assertRedirect(route('help-applications.edit', $application));
        $document = HelpApplicationDocument::sole();
        $raw = DB::table('help_application_documents')->where('id', $document->id)->first();
        $this->assertSame('png', $document->extension);
        $this->assertSame('image/png', $document->mime_type);
        $this->assertSame(strlen($acceptedBytes), $document->size_bytes);
        $this->assertSame(hash('sha256', $acceptedBytes), $document->checksum);
        $this->assertSame('sha256', $document->checksum_algorithm);
        $this->assertSame('applicant', $document->uploader_kind->value);
        $this->assertSame($actor->id, $document->uploaded_by);
        $this->assertSame('accepted_unscanned', $document->security_status->value);
        $this->assertNull($document->scanned_at);
        $this->assertNotSame($document->original_name, $raw->original_name);
        $this->assertNotSame($document->checksum, $raw->checksum);
        $this->assertStringStartsWith("applications/{$application->reference}/documents/", $document->storage_path);
        Storage::disk('help_application_documents')->assertExists($document->storage_path);
        $this->assertSame($acceptedBytes, Storage::disk('help_application_documents')->get($document->storage_path));
        $uploadAudit = AuditLog::where('action', 'help_application.document_uploaded')->sole();
        $this->assertSame(['document_present' => false], $uploadAudit->old_values);
        $this->assertSame(['document_present' => true, 'accepted_unscanned' => true, 'malware_scanned' => false], $uploadAudit->new_values);
        $serializedAudit = serialize($uploadAudit->getAttributes());
        foreach ([$document->original_name, $document->storage_path, $document->reference, $document->purpose->value, $document->mime_type, $document->checksum, 'PRIVATE-FILENAME-SENTINEL'] as $private) {
            $this->assertStringNotContainsString($private, $serializedAudit);
        }

        $edit = $this->get(route('help-applications.edit', $application));
        $edit->assertOk()
            ->assertSeeText('PRIVATE-FILENAME-SENTINEL.png')
            ->assertSeeText('Medical report / تقرير طبي')
            ->assertSeeText('PNG')
            ->assertSeeText(number_format($document->size_bytes, 0, '.', ',').' bytes / بايت')
            ->assertDontSee('download', false)
            ->assertDontSee('preview', false);
        $this->assertStringNotContainsString('Number::fileSize', file_get_contents(resource_path('views/applicant/help-applications/edit.blade.php')));

        $this->actingAs($actor)->delete(route('help-applications.documents.destroy', [$application, $document]))
            ->assertRedirect(route('help-applications.edit', $application));
        $document->refresh();
        $this->assertNotNull($document->removed_at);
        $this->assertSame($actor->id, $document->removed_by);
        Storage::disk('help_application_documents')->assertMissing($document->storage_path);
        $removeAudit = AuditLog::where('action', 'help_application.document_removed')->sole();
        $this->assertSame(['document_active' => true], $removeAudit->old_values);
        $this->assertSame(['document_active' => false], $removeAudit->new_values);
    }

    public function test_foreign_document_is_not_resolved_and_invalid_upload_does_not_flash_file(): void
    {
        Storage::fake('help_application_documents');
        $actor = User::factory()->create();
        $application = HelpApplication::factory()->for($actor, 'applicant')->create();
        $foreign = HelpApplicationDocument::factory()->create();

        $this->actingAs($actor)->delete(route('help-applications.documents.destroy', [$application, $foreign]))->assertNotFound();
        $response = $this->actingAs($actor)->from(route('help-applications.edit', $application))->post(route('help-applications.documents.store', $application), [
            'purpose' => 'invalid',
            'document' => UploadedFile::fake()->create('private.txt', 1, 'text/plain'),
        ]);
        $response->assertRedirect(route('help-applications.edit', $application))->assertSessionHasErrors(['purpose']);
        $this->assertArrayNotHasKey('document', session()->getOldInput());
        $this->assertArrayNotHasKey('purpose', session()->getOldInput());
        $this->assertDatabaseCount('help_application_documents', 1);
    }

    public function test_locked_database_reference_alone_determines_uploaded_path(): void
    {
        Storage::fake('help_application_documents');
        $actor = User::factory()->create();
        $application = HelpApplication::factory()->for($actor, 'applicant')->create();
        $databaseReference = $application->reference;
        $application->reference = '550e8400-e29b-41d4-a716-446655440000';

        $document = app(HelpApplicationDocumentService::class)->upload(
            $actor,
            $application,
            UploadedFile::fake()->image('evidence.png', 10, 10),
            HelpApplicationDocumentPurpose::Other,
            Request::create('/help-applications/documents', 'POST'),
        );

        $this->assertStringStartsWith("applications/{$databaseReference}/documents/", $document->storage_path);
        $this->assertStringNotContainsString($application->reference, $document->storage_path);
        Storage::disk('help_application_documents')->assertExists($document->storage_path);
    }

    public function test_stored_byte_mismatch_rolls_back_and_compensates_generated_path(): void
    {
        $actor = User::factory()->create();
        $application = HelpApplication::factory()->for($actor, 'applicant')->create();
        $mismatch = fopen('php://temp', 'w+b');
        fwrite($mismatch, 'substituted');
        rewind($mismatch);
        $deletedPath = null;
        $disk = Mockery::mock();
        $disk->shouldReceive('putFileAs')->once()->andReturnUsing(fn (string $directory, UploadedFile $file, string $name): string => $directory.'/'.$name);
        $disk->shouldReceive('readStream')->once()->andReturn($mismatch);
        $disk->shouldReceive('delete')->once()->andReturnUsing(function (string $path) use (&$deletedPath): bool {
            $deletedPath = $path;

            return true;
        });
        Storage::shouldReceive('disk')->andReturn($disk);

        try {
            app(HelpApplicationDocumentService::class)->upload(
                $actor,
                $application,
                UploadedFile::fake()->image('evidence.png', 10, 10),
                HelpApplicationDocumentPurpose::Other,
                Request::create('/help-applications/documents', 'POST'),
            );
            $this->fail('Stored-byte substitution was accepted.');
        } catch (LogicException $exception) {
            $this->assertSame('Private document storage verification failed.', $exception->getMessage());
        } finally {
            if (is_resource($mismatch)) {
                fclose($mismatch);
            }
        }

        $this->assertIsString($deletedPath);
        $this->assertStringStartsWith("applications/{$application->reference}/documents/", $deletedPath);
        $this->assertDatabaseCount('help_application_documents', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    #[DataProvider('uploadStorageFailureModes')]
    public function test_upload_storage_failures_rollback_and_compensate_only_generated_owned_path(string $mode): void
    {
        $actor = User::factory()->create();
        $application = HelpApplication::factory()->for($actor, 'applicant')->create();
        $file = UploadedFile::fake()->image('PRIVATE-STORAGE-SENTINEL.png', 10, 10);
        $bytes = file_get_contents($file->getRealPath());
        $deleted = [];
        $unexpected = 'foreign/unexpected.png';
        $disk = Mockery::mock();
        $put = $disk->shouldReceive('putFileAs')->once();
        if ($mode === 'throw') {
            $put->andThrow(new RuntimeException('storage sentinel'));
        } elseif ($mode === 'returned-path') {
            $put->andReturn($unexpected);
        } else {
            $put->andReturnUsing(fn (string $directory, UploadedFile $upload, string $name): string => $directory.'/'.$name);
            $stream = match ($mode) {
                'no-stream' => false,
                'short' => $this->stream(substr($bytes, 0, -1)),
                'long' => $this->stream($bytes.'x'),
                'same-size-hash' => $this->stream(str_repeat('x', strlen($bytes))),
            };
            $disk->shouldReceive('readStream')->once()->andReturn($stream);
        }
        $disk->shouldReceive('delete')->once()->andReturnUsing(function (string $path) use (&$deleted): bool {
            $deleted[] = $path;

            return true;
        });
        Storage::shouldReceive('disk')->with('help_application_documents')->andReturn($disk);

        try {
            app(HelpApplicationDocumentService::class)->upload($actor, $application, $file, HelpApplicationDocumentPurpose::Other, Request::create('/', 'POST'));
            $this->fail("{$mode} storage failure was accepted.");
        } catch (RuntimeException|LogicException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }
        $this->assertCount(1, $deleted);
        $this->assertStringStartsWith("applications/{$application->reference}/documents/", $deleted[0]);
        $this->assertNotSame($unexpected, $deleted[0]);
        $this->assertDatabaseCount('help_application_documents', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public static function uploadStorageFailureModes(): array
    {
        return [
            'storage throws' => ['throw'], 'returned path mismatch' => ['returned-path'],
            'stream unavailable' => ['no-stream'], 'stored shorter' => ['short'],
            'stored longer' => ['long'], 'same size different checksum' => ['same-size-hash'],
        ];
    }

    public function test_upload_audit_failure_rolls_back_metadata_and_compensates_without_masking_original(): void
    {
        Storage::fake('help_application_documents');
        $actor = User::factory()->create();
        $application = HelpApplication::factory()->for($actor, 'applicant')->create();
        $this->mock(AuditLogger::class)->shouldReceive('log')->once()->andThrow(new RuntimeException('original audit sentinel'));
        try {
            app(HelpApplicationDocumentService::class)->upload($actor, $application, UploadedFile::fake()->image('audit.png'), HelpApplicationDocumentPurpose::Other, Request::create('/', 'POST'));
            $this->fail('Upload audit failure was swallowed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('original audit sentinel', $exception->getMessage());
        }
        $this->assertDatabaseCount('help_application_documents', 0);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertSame([], Storage::disk('help_application_documents')->allFiles());
    }

    public function test_metadata_save_failure_rolls_back_and_compensates_generated_file(): void
    {
        Storage::fake('help_application_documents');
        $actor = User::factory()->create();
        $application = HelpApplication::factory()->for($actor, 'applicant')->create();
        HelpApplicationDocument::saving(fn () => throw new RuntimeException('metadata save sentinel'));
        try {
            app(HelpApplicationDocumentService::class)->upload($actor, $application, UploadedFile::fake()->image('metadata.png'), HelpApplicationDocumentPurpose::Other, Request::create('/', 'POST'));
            $this->fail('Metadata failure was swallowed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('metadata save sentinel', $exception->getMessage());
        } finally {
            HelpApplicationDocument::flushEventListeners();
        }
        $this->assertDatabaseCount('help_application_documents', 0);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertSame([], Storage::disk('help_application_documents')->allFiles());
    }

    public function test_upload_warning_logging_failure_never_masks_after_commit_exception(): void
    {
        Storage::fake('help_application_documents');
        $actor = User::factory()->create();
        $application = HelpApplication::factory()->for($actor, 'applicant')->create();
        $realLogger = new AuditLogger;
        $logger = Mockery::mock($realLogger)->makePartial();
        $logger->shouldReceive('log')->once()->andReturnUsing(function (...$arguments) use ($realLogger) {
            $audit = $realLogger->log(...$arguments);
            DB::afterCommit(fn () => throw new RuntimeException('original committed sentinel'));

            return $audit;
        });
        $this->app->instance(AuditLogger::class, $logger);
        Log::shouldReceive('warning')->once()->andThrow(new RuntimeException('logging sentinel'));
        try {
            app(HelpApplicationDocumentService::class)->upload($actor, $application, UploadedFile::fake()->image('logging.png'), HelpApplicationDocumentPurpose::Other, Request::create('/', 'POST'));
            $this->fail('After-commit exception was swallowed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('original committed sentinel', $exception->getMessage());
        }
        $document = HelpApplicationDocument::sole();
        Storage::disk('help_application_documents')->assertExists($document->storage_path);
    }

    public function test_after_commit_exception_preserves_committed_document_file_and_safe_audit(): void
    {
        Storage::fake('help_application_documents');
        $actor = User::factory()->create();
        $application = HelpApplication::factory()->for($actor, 'applicant')->create();
        $realLogger = new AuditLogger;
        $logger = Mockery::mock($realLogger)->makePartial();
        $logger->shouldReceive('log')->once()->andReturnUsing(function (...$arguments) use ($realLogger) {
            $audit = $realLogger->log(...$arguments);
            DB::afterCommit(fn () => throw new RuntimeException('after commit sentinel'));

            return $audit;
        });
        $this->app->instance(AuditLogger::class, $logger);
        Log::spy();
        try {
            app(HelpApplicationDocumentService::class)->upload($actor, $application, UploadedFile::fake()->image('committed.png'), HelpApplicationDocumentPurpose::Other, Request::create('/', 'POST'));
            $this->fail('After-commit exception did not escape.');
        } catch (RuntimeException $exception) {
            $this->assertSame('after commit sentinel', $exception->getMessage());
        }
        $document = HelpApplicationDocument::sole();
        Storage::disk('help_application_documents')->assertExists($document->storage_path);
        $this->assertDatabaseHas('audit_logs', ['action' => 'help_application.document_uploaded', 'subject_id' => $document->id]);
        Log::shouldHaveReceived('warning')->once()->with('Private Help Application document cleanup was not completed.', ['application_id' => $application->id]);
    }

    #[DataProvider('committedLookupOutcomes')]
    public function test_commit_state_outcome_controls_compensation_without_foreign_deletion(?bool $outcome): void
    {
        $actor = User::factory()->create();
        $application = HelpApplication::factory()->for($actor, 'applicant')->create();
        $disk = Mockery::mock();
        $disk->shouldReceive('putFileAs')->once()->andThrow(new RuntimeException('original storage sentinel'));
        Storage::shouldReceive('disk')->with('help_application_documents')->andReturn($disk);
        $service = new class(app(HelpApplicationDocumentInspector::class), new AuditLogger, $outcome) extends HelpApplicationDocumentService
        {
            public array $deleted = [];

            public array $warnings = [];

            public function __construct($inspector, $logger, private readonly ?bool $outcome)
            {
                parent::__construct($inspector, $logger);
            }

            protected function committed(int $appId, string $reference, string $path): ?bool
            {
                return $this->outcome;
            }

            protected function deleteBestEffort(?string $path, string $appRef, string $docRef, string $extension, int $appId, ?int $docId): void
            {
                $this->deleted[] = $path;
            }

            protected function warn(int $appId, ?int $docId): void
            {
                $this->warnings[] = ['application_id' => $appId, 'document_id' => $docId];
            }
        };
        try {
            $service->upload($actor, $application, UploadedFile::fake()->image('outcome.png'), HelpApplicationDocumentPurpose::Other, Request::create('/', 'POST'));
            $this->fail('Storage exception was swallowed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('original storage sentinel', $exception->getMessage());
        }
        if ($outcome === false) {
            $this->assertCount(1, $service->deleted);
            $this->assertSame([], $service->warnings);
            $this->assertStringStartsWith("applications/{$application->reference}/documents/", $service->deleted[0]);
        } else {
            $this->assertSame([], $service->deleted);
            $this->assertSame([['application_id' => $application->id, 'document_id' => null]], $service->warnings);
        }
    }

    public static function committedLookupOutcomes(): array
    {
        return ['committed true' => [true], 'not committed' => [false], 'lookup unavailable' => [null]];
    }

    private function stream(string $bytes)
    {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $bytes);
        rewind($stream);

        return $stream;
    }

    public function test_document_routes_have_exact_methods_uuid_binding_middleware_and_throttles(): void
    {
        $routes = app('router')->getRoutes();
        $store = $routes->getByName('help-applications.documents.store');
        $destroy = $routes->getByName('help-applications.documents.destroy');
        $this->assertSame(['POST'], $store->methods());
        $this->assertSame(['DELETE'], $destroy->methods());
        $this->assertSame('help-applications/{helpApplication}/documents', $store->uri());
        $this->assertSame('help-applications/{helpApplication}/documents/{helpApplicationDocument}', $destroy->uri());
        $this->assertContains('auth', $store->gatherMiddleware());
        $this->assertContains('role:user', $store->gatherMiddleware());
        $this->assertContains('throttle:6,1', $store->gatherMiddleware());
        $this->assertContains('throttle:10,1', $destroy->gatherMiddleware());
        $this->assertSame('reference', (new HelpApplication)->getRouteKeyName());
        $this->assertSame('reference', (new HelpApplicationDocument)->getRouteKeyName());
    }

    public function test_guest_privileged_ineligible_foreign_and_numeric_route_access_never_touches_storage(): void
    {
        Storage::fake('help_application_documents');
        $owner = User::factory()->create();
        $application = HelpApplication::factory()->for($owner, 'applicant')->create();
        $document = HelpApplicationDocument::factory()->for($application, 'application')->create();
        $payload = ['purpose' => 'other', 'document' => UploadedFile::fake()->image('denied.png')];
        $this->post(route('help-applications.documents.store', $application), $payload)->assertRedirect(route('login'));
        $this->delete(route('help-applications.documents.destroy', [$application, $document]))->assertRedirect(route('login'));

        foreach ([User::factory()->admin()->create(), User::factory()->superAdmin()->create()] as $privileged) {
            $this->actingAs($privileged)->post(route('help-applications.documents.store', $application), $payload)->assertForbidden();
            $this->actingAs($privileged)->delete(route('help-applications.documents.destroy', [$application, $document]))->assertForbidden();
        }
        foreach ([User::factory()->disabled()->create(), User::factory()->mustChangePassword()->create()] as $ineligible) {
            $this->actingAs($ineligible)->post(route('help-applications.documents.store', $application), $payload)->assertRedirect();
        }
        $foreign = User::factory()->create();
        $this->actingAs($foreign)->post(route('help-applications.documents.store', $application), $payload)->assertForbidden();
        $this->actingAs($foreign)->delete(route('help-applications.documents.destroy', [$application, $document]))->assertForbidden();
        $this->actingAs($owner)->post('/help-applications/'.$application->id.'/documents', $payload)->assertNotFound();
        $this->actingAs($owner)->delete('/help-applications/'.$application->reference.'/documents/'.$document->id)->assertNotFound();
        $this->assertSame([], Storage::disk('help_application_documents')->allFiles());
        $this->assertNull($document->fresh()->removed_at);
    }

    public function test_missing_and_invalid_fields_are_private_and_write_nothing(): void
    {
        Storage::fake('help_application_documents');
        $actor = User::factory()->create();
        $application = HelpApplication::factory()->for($actor, 'applicant')->create();
        $sentinel = 'PRIVATE-WORKFLOW-SENTINEL';
        foreach ([
            'missing-document' => ['purpose' => 'other'],
            'missing-purpose' => ['document' => UploadedFile::fake()->image($sentinel.'.png')],
            'invalid-purpose' => ['purpose' => $sentinel, 'document' => UploadedFile::fake()->image($sentinel.'.png')],
        ] as $case => $payload) {
            $response = $this->actingAs($actor)->from(route('help-applications.edit', $application))->post(route('help-applications.documents.store', $application), $payload);
            $response->assertRedirect(route('help-applications.edit', $application))->assertSessionHasErrors();
            $serializedSession = serialize(session()->all());
            $this->assertStringNotContainsString($sentinel, $serializedSession, $case);
            $this->assertArrayNotHasKey('document', session()->getOldInput(), $case);
            $this->assertArrayNotHasKey('purpose', session()->getOldInput(), $case);
            $this->assertStringNotContainsString($sentinel, $response->headers->get('Location', ''), $case);
            $this->get(route('help-applications.edit', $application))->assertDontSee($sentinel, false);
        }
        $this->assertDatabaseCount('help_application_documents', 0);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertSame([], Storage::disk('help_application_documents')->allFiles());
    }

    public function test_all_five_exact_controlled_purposes_persist(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        Storage::fake('help_application_documents');
        $actor = User::factory()->create();
        $application = HelpApplication::factory()->for($actor, 'applicant')->create();
        foreach (HelpApplicationDocumentPurpose::cases() as $purpose) {
            $this->actingAs($actor)->post(route('help-applications.documents.store', $application), [
                'purpose' => $purpose->value,
                'document' => UploadedFile::fake()->image("{$purpose->value}.png", 5, 5),
            ])->assertRedirect(route('help-applications.edit', $application));
        }
        $this->assertSame(array_column(HelpApplicationDocumentPurpose::cases(), 'value'), $application->documents()->orderBy('id')->get()->map(fn (HelpApplicationDocument $document): string => $document->purpose->value)->all());

    }

    public function test_exact_count_quota_accepts_tenth_rejects_eleventh_and_ignores_removed(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        Storage::fake('help_application_documents');
        $actor = User::factory()->create();
        $application = HelpApplication::factory()->for($actor, 'applicant')->create();
        HelpApplicationDocument::factory()->count(9)->for($application, 'application')->create();
        HelpApplicationDocument::factory()->count(3)->for($application, 'application')->removedBy($actor)->create();

        $this->actingAs($actor)->post(route('help-applications.documents.store', $application), [
            'purpose' => 'other', 'document' => UploadedFile::fake()->image('tenth.png', 5, 5),
        ])->assertRedirect(route('help-applications.edit', $application));
        $this->assertSame(10, $application->documents()->active()->count());
        $files = Storage::disk('help_application_documents')->allFiles();

        $this->actingAs($actor)->post(route('help-applications.documents.store', $application), [
            'purpose' => 'other', 'document' => UploadedFile::fake()->image('eleventh.png', 5, 5),
        ])->assertSessionHasErrors('document');
        $this->assertSame(10, $application->documents()->active()->count());
        $this->assertSame($files, Storage::disk('help_application_documents')->allFiles());
    }

    public function test_exact_combined_byte_quota_accepts_limit_rejects_one_over_and_ignores_removed(): void
    {
        Storage::fake('help_application_documents');
        $actor = User::factory()->create();
        $limit = 52428800;
        foreach ([0 => true, 1 => false] as $over => $accepted) {
            $caseActor = User::factory()->create();
            $application = HelpApplication::factory()->for($caseActor, 'applicant')->create();
            $file = UploadedFile::fake()->image("boundary-{$over}.png", 5, 5);
            HelpApplicationDocument::factory()->for($application, 'application')->create(['size_bytes' => $limit - $file->getSize() + $over]);
            HelpApplicationDocument::factory()->for($application, 'application')->removedBy($caseActor)->create(['size_bytes' => $limit]);
            $beforeFiles = Storage::disk('help_application_documents')->allFiles();
            $response = $this->actingAs($caseActor)->post(route('help-applications.documents.store', $application), ['purpose' => 'other', 'document' => $file]);
            if ($accepted) {
                $response->assertRedirect(route('help-applications.edit', $application));
                $this->assertSame($limit, (int) $application->documents()->active()->sum('size_bytes'));
            } else {
                $response->assertSessionHasErrors('document');
                $this->assertSame($beforeFiles, Storage::disk('help_application_documents')->allFiles());
                $this->assertSame(2, $application->documents()->count());
                $this->assertSame(0, AuditLog::where('subject_type', (new HelpApplicationDocument)->getMorphClass())->whereIn('subject_id', $application->documents()->pluck('id'))->count());
            }
        }
    }

    public function test_nested_upload_and_removal_transactions_are_rejected_before_mutation(): void
    {
        Storage::fake('help_application_documents');
        $actor = User::factory()->create();
        $application = HelpApplication::factory()->for($actor, 'applicant')->create();
        $document = HelpApplicationDocument::factory()->for($application, 'application')->create();
        DB::beginTransaction();
        try {
            foreach ([
                fn () => app(HelpApplicationDocumentService::class)->upload($actor, $application, UploadedFile::fake()->image('nested.png'), HelpApplicationDocumentPurpose::Other, Request::create('/', 'POST')),
                fn () => app(HelpApplicationDocumentService::class)->remove($actor, $application, $document, Request::create('/', 'DELETE')),
            ] as $operation) {
                try {
                    $operation();
                    $this->fail('Nested mutation was accepted.');
                } catch (LogicException $exception) {
                    $this->assertSame('Document mutations require a top-level transaction.', $exception->getMessage());
                }
            }
        } finally {
            DB::rollBack();
        }
        $this->assertNull($document->fresh()->removed_at);
    }

    public function test_locked_draft_state_and_unsafe_removal_path_fail_before_storage_deletion(): void
    {
        Storage::fake('help_application_documents');
        $actor = User::factory()->create();
        $application = HelpApplication::factory()->for($actor, 'applicant')->create();
        $stale = clone $application;
        $application->status = HelpApplicationStatus::Pending;
        $application->save();
        $this->assertThrows(
            fn () => app(HelpApplicationDocumentService::class)->upload($actor, $stale, UploadedFile::fake()->image('stale.png'), HelpApplicationDocumentPurpose::Other, Request::create('/', 'POST')),
            AuthorizationException::class,
        );

        $application->status = HelpApplicationStatus::Draft;
        $application->open_slot = false;
        $application->save();
        $this->assertThrows(
            fn () => app(HelpApplicationDocumentService::class)->upload($actor, $stale, UploadedFile::fake()->image('closed.png'), HelpApplicationDocumentPurpose::Other, Request::create('/', 'POST')),
            AuthorizationException::class,
        );

        $application->open_slot = true;
        $application->save();
        $foreignApplicationReference = (string) Str::uuid();
        foreach (['traversal', 'foreign-application', 'foreign-document', 'mismatched-extension', 'malformed-uuid', 'public-path'] as $case) {
            $document = HelpApplicationDocument::factory()->for($application, 'application')->create();
            $path = match ($case) {
                'traversal' => '../'.$document->storage_path,
                'foreign-application' => str_replace($application->reference, $foreignApplicationReference, $document->storage_path),
                'foreign-document' => str_replace($document->reference, (string) Str::uuid(), $document->storage_path),
                'mismatched-extension' => preg_replace('/\.pdf\z/', '.png', $document->storage_path),
                'malformed-uuid' => str_replace($document->reference, 'not-a-uuid', $document->storage_path),
                'public-path' => 'public/'.$document->storage_path,
            };
            DB::table('help_application_documents')->where('id', $document->id)->update(['storage_path' => $path]);
            $document->refresh();
            try {
                app(HelpApplicationDocumentService::class)->remove($actor, $application, $document, Request::create('/', 'DELETE'));
                $this->fail("{$case} path was accepted.");
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode(), $case);
            }
            $this->assertNull($document->fresh()->removed_at, $case);
        }
        $this->assertSame([], Storage::disk('help_application_documents')->allFiles());
    }

    #[DataProvider('cleanupFailureModes')]
    public function test_removal_commits_tombstone_and_audit_before_best_effort_cleanup_failures(string $mode): void
    {
        Storage::fake('help_application_documents');
        $actor = User::factory()->create();
        $application = HelpApplication::factory()->for($actor, 'applicant')->create();
        $document = HelpApplicationDocument::factory()->for($application, 'application')->other()->acceptedUnscanned()->create();
        $realDisk = Storage::disk('help_application_documents');
        $realDisk->put($document->storage_path, 'evidence');
        $mockDisk = Mockery::mock($realDisk)->makePartial();
        $mockDisk->shouldReceive('delete')->once()->with($document->storage_path)->andReturnUsing(function () use ($mode, $document): bool {
            $this->assertNotNull($document->fresh()->removed_at);
            $this->assertDatabaseHas('audit_logs', ['action' => 'help_application.document_removed', 'subject_id' => $document->id]);
            if ($mode !== 'false') {
                throw new RuntimeException('cleanup sentinel');
            }

            return false;
        });
        Storage::shouldReceive('disk')->with('help_application_documents')->andReturn($mockDisk);
        if ($mode === 'logging-throw') {
            Log::shouldReceive('warning')->once()->andThrow(new RuntimeException('logging sentinel'));
        } else {
            Log::spy();
        }

        app(HelpApplicationDocumentService::class)->remove($actor, $application, $document, Request::create('/', 'DELETE'));
        $removed = $document->fresh();
        $this->assertNotNull($removed->removed_at);
        $this->assertSame($actor->id, $removed->removed_by);
        foreach (['storage_path', 'original_name', 'extension', 'mime_type', 'size_bytes', 'checksum', 'purpose', 'security_status'] as $field) {
            $this->assertEquals($document->getRawOriginal($field), $removed->getRawOriginal($field), "{$mode}: {$field}");
        }
        $audit = AuditLog::where('action', 'help_application.document_removed')->where('subject_id', $document->id)->sole();
        $this->assertSame(['document_active' => true], $audit->old_values);
        $this->assertSame(['document_active' => false], $audit->new_values);
    }

    public static function cleanupFailureModes(): array
    {
        return ['delete false' => ['false'], 'delete throws' => ['throw'], 'logging throws' => ['logging-throw']];
    }

    public function test_removal_audit_failure_rolls_back_and_repeated_removal_never_deletes_twice(): void
    {
        Storage::fake('help_application_documents');
        $actor = User::factory()->create();
        $application = HelpApplication::factory()->for($actor, 'applicant')->create();
        $document = HelpApplicationDocument::factory()->for($application, 'application')->create();
        Storage::disk('help_application_documents')->put($document->storage_path, 'evidence');
        $this->mock(AuditLogger::class)->shouldReceive('log')->once()->andThrow(new RuntimeException('audit sentinel'));
        try {
            app(HelpApplicationDocumentService::class)->remove($actor, $application, $document, Request::create('/', 'DELETE'));
            $this->fail('Removal audit failure was swallowed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('audit sentinel', $exception->getMessage());
        }
        $this->assertNull($document->fresh()->removed_at);
        Storage::disk('help_application_documents')->assertExists($document->storage_path);
        $this->assertDatabaseCount('audit_logs', 0);

        $this->app->forgetInstance(AuditLogger::class);
        app(HelpApplicationDocumentService::class)->remove($actor, $application, $document, Request::create('/', 'DELETE'));
        $this->assertThrows(
            fn () => app(HelpApplicationDocumentService::class)->remove($actor, $application, $document, Request::create('/', 'DELETE')),
            ModelNotFoundException::class,
        );
        $this->assertSame(1, AuditLog::where('action', 'help_application.document_removed')->count());
    }
}
