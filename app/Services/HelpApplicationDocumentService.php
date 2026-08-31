<?php

namespace App\Services;

use App\Enums\HelpApplicationDocumentPurpose;
use App\Enums\HelpApplicationDocumentSecurityStatus;
use App\Enums\HelpApplicationDocumentUploaderKind;
use App\Models\HelpApplication;
use App\Models\HelpApplicationDocument;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Throwable;

class HelpApplicationDocumentService
{
    public function __construct(private readonly HelpApplicationDocumentInspector $inspector, private readonly AuditLogger $auditLogger) {}

    public function upload(User $actor, HelpApplication $application, UploadedFile $file, HelpApplicationDocumentPurpose $purpose, Request $request): HelpApplicationDocument
    {
        $this->topLevel();
        $inspected = $this->inspector->inspect($file);
        $reference = (string) Str::uuid();
        $path = null;
        $lockedApplicationReference = null;
        $stored = false;
        try {
            return DB::transaction(function () use ($actor, $application, $file, $purpose, $request, $inspected, $reference, &$path, &$lockedApplicationReference, &$stored): HelpApplicationDocument {
                $lockedActor = User::query()->lockForUpdate()->findOrFail($actor->getKey());
                $lockedApplication = HelpApplication::query()->lockForUpdate()->findOrFail($application->getKey());
                Gate::forUser($lockedActor)->authorize('update', $lockedApplication);
                $lockedApplicationReference = $lockedApplication->reference;
                $path = HelpApplicationDocumentPath::make($lockedApplicationReference, $reference, $inspected->extension);
                $quota = HelpApplicationDocument::query()->forApplication($lockedApplication)->active()->lockForUpdate()->get(['id', 'size_bytes']);
                if ($quota->count() >= config('help_application_documents.limits.max_active_documents')
                    || $quota->sum('size_bytes') + $inspected->sizeBytes > config('help_application_documents.limits.max_combined_active_bytes')) {
                    throw ValidationException::withMessages(['document' => 'The private document limit for this draft has been reached. / تم بلوغ حد المستندات الخاصة لهذه المسودة.']);
                }
                $stored = true;
                $returned = Storage::disk(config('help_application_documents.disk'))->putFileAs(dirname($path), $file, basename($path));
                if ($returned !== $path) {
                    throw new LogicException('Private document storage failed.');
                }
                if (! $this->storedBytesMatch($path, $inspected->sizeBytes, $inspected->checksum)) {
                    throw new LogicException('Private document storage verification failed.');
                }
                $document = new HelpApplicationDocument;
                $document->reference = $reference;
                $document->help_application_id = $lockedApplication->getKey();
                $document->storage_path = $path;
                $document->original_name = $this->displayName($file->getClientOriginalName());
                $document->extension = $inspected->extension;
                $document->mime_type = $inspected->mimeType;
                $document->size_bytes = $inspected->sizeBytes;
                $document->checksum = $inspected->checksum;
                $document->checksum_algorithm = 'sha256';
                $document->purpose = $purpose;
                $document->uploader_kind = HelpApplicationDocumentUploaderKind::Applicant;
                $document->uploaded_by = $lockedActor->getKey();
                $document->security_status = HelpApplicationDocumentSecurityStatus::AcceptedUnscanned;
                $document->scanned_at = null;
                $document->save();
                $this->auditLogger->log('help_application.document_uploaded', $lockedActor, $document,
                    ['document_present' => false], ['document_present' => true, 'accepted_unscanned' => true, 'malware_scanned' => false], $request);

                return $document;
            });
        } catch (Throwable $exception) {
            if ($stored) {
                $committed = is_string($path) ? $this->committed($application->getKey(), $reference, $path) : null;
                if ($committed === false) {
                    $this->deleteBestEffort($path, (string) $lockedApplicationReference, $reference, $inspected->extension, $application->getKey(), null);
                } else {
                    $this->warn($application->getKey(), null);
                }
            }
            throw $exception;
        }
    }

    protected function storedBytesMatch(string $path, int $expectedSize, string $expectedChecksum): bool
    {
        try {
            $stream = Storage::disk(config('help_application_documents.disk'))->readStream($path);
            if (! is_resource($stream)) {
                return false;
            }
            $hash = hash_init('sha256');
            $size = 0;
            try {
                while (! feof($stream)) {
                    $chunk = fread($stream, 8192);
                    if ($chunk === false) {
                        return false;
                    }
                    $size += strlen($chunk);
                    if ($size > $expectedSize) {
                        return false;
                    }
                    hash_update($hash, $chunk);
                }
            } finally {
                fclose($stream);
            }

            return $size === $expectedSize && hash_equals($expectedChecksum, hash_final($hash));
        } catch (Throwable) {
            return false;
        }
    }

    public function remove(User $actor, HelpApplication $application, HelpApplicationDocument $document, Request $request): void
    {
        $this->topLevel();
        [$path, $appRef, $docRef, $extension, $appId, $docId] = DB::transaction(function () use ($actor, $application, $document, $request): array {
            $lockedActor = User::query()->lockForUpdate()->findOrFail($actor->getKey());
            $lockedApplication = HelpApplication::query()->lockForUpdate()->findOrFail($application->getKey());
            Gate::forUser($lockedActor)->authorize('update', $lockedApplication);
            $lockedDocument = HelpApplicationDocument::query()->forApplication($lockedApplication)->active()->lockForUpdate()->findOrFail($document->getKey());
            Gate::forUser($lockedActor)->authorize('delete', $lockedDocument);
            if (! HelpApplicationDocumentPath::isOwnedBy($lockedDocument->storage_path, $lockedApplication->reference, $lockedDocument->reference, $lockedDocument->extension)) {
                abort(403);
            }
            $lockedDocument->removed_at = now();
            $lockedDocument->removed_by = $lockedActor->getKey();
            $lockedDocument->save();
            $this->auditLogger->log('help_application.document_removed', $lockedActor, $lockedDocument,
                ['document_active' => true], ['document_active' => false], $request);

            return [$lockedDocument->storage_path, $lockedApplication->reference, $lockedDocument->reference, $lockedDocument->extension, $lockedApplication->getKey(), $lockedDocument->getKey()];
        });
        $this->deleteBestEffort($path, $appRef, $docRef, $extension, $appId, $docId);
    }

    private function displayName(string $name): string
    {
        $parts = preg_split('~[\\\\/]~u', $name);
        $name = end($parts) ?: '';
        $name = preg_replace('/[\x00-\x1F\x7F\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{206F}\x{FEFF}]/u', '', $name) ?? '';
        $name = trim($name, " \t\n\r\0\x0B.");

        return $name === '' ? 'supporting-document' : mb_substr($name, 0, 160, 'UTF-8');
    }

    protected function deleteBestEffort(?string $path, string $appRef, string $docRef, string $extension, int $appId, ?int $docId): void
    {
        if (! is_string($path) || ! HelpApplicationDocumentPath::isOwnedBy($path, $appRef, $docRef, $extension)) {
            return;
        }
        try {
            if (! Storage::disk(config('help_application_documents.disk'))->delete($path)) {
                $this->warn($appId, $docId);
            }
        } catch (Throwable) {
            $this->warn($appId, $docId);
        }
    }

    protected function committed(int $appId, string $reference, string $path): ?bool
    {
        if (DB::transactionLevel() !== 0) {
            return null;
        }
        try {
            return HelpApplicationDocument::query()->where('help_application_id', $appId)->where('reference', $reference)->where('storage_path', $path)->exists();
        } catch (Throwable) {
            return null;
        }
    }

    protected function warn(int $appId, ?int $docId): void
    {
        try {
            Log::warning('Private Help Application document cleanup was not completed.', array_filter(['application_id' => $appId, 'document_id' => $docId]));
        } catch (Throwable) {
        }
    }

    private function topLevel(): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Document mutations require a top-level transaction.');
        }
    }
}
