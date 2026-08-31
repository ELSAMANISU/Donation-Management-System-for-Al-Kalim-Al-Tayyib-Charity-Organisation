<?php

namespace Database\Factories;

use App\Enums\HelpApplicationDocumentPurpose;
use App\Enums\HelpApplicationDocumentSecurityStatus;
use App\Enums\HelpApplicationDocumentUploaderKind;
use App\Models\HelpApplication;
use App\Models\HelpApplicationDocument;
use App\Models\User;
use App\Services\HelpApplicationDocumentPath;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<HelpApplicationDocument> */
class HelpApplicationDocumentFactory extends Factory
{
    protected $model = HelpApplicationDocument::class;

    public function definition(): array
    {
        $reference = (string) Str::uuid();

        return [
            'reference' => $reference,
            'help_application_id' => HelpApplication::factory(),
            'storage_path' => function (array $attributes) use ($reference): string {
                $application = HelpApplication::query()->findOrFail($attributes['help_application_id']);

                return HelpApplicationDocumentPath::make($application->reference, $reference, 'pdf');
            },
            'original_name' => 'synthetic-supporting-document.pdf',
            'extension' => 'pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'checksum' => hash('sha256', 'synthetic-help-application-document-'.$reference),
            'checksum_algorithm' => 'sha256',
            'purpose' => null,
            'uploader_kind' => HelpApplicationDocumentUploaderKind::Applicant,
            'uploaded_by' => fn (array $attributes): int => HelpApplication::query()
                ->findOrFail($attributes['help_application_id'])->applicant_id,
            'security_status' => HelpApplicationDocumentSecurityStatus::Pending,
            'scanned_at' => null,
            'removed_at' => null,
            'removed_by' => null,
        ];
    }

    public function acceptedUnscanned(): static
    {
        return $this->state(fn () => [
            'security_status' => HelpApplicationDocumentSecurityStatus::AcceptedUnscanned,
            'scanned_at' => null,
        ]);
    }

    public function clean(): static
    {
        return $this->state(fn () => [
            'security_status' => HelpApplicationDocumentSecurityStatus::Clean,
            'scanned_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn () => [
            'security_status' => HelpApplicationDocumentSecurityStatus::Rejected,
            'scanned_at' => null,
        ]);
    }

    public function removedBy(User $actor): static
    {
        return $this->state(fn () => ['removed_at' => now(), 'removed_by' => $actor->getKey()]);
    }

    public function uploadedByAdministrator(User $administrator): static
    {
        return $this->state(fn () => [
            'uploader_kind' => HelpApplicationDocumentUploaderKind::Administrator,
            'uploaded_by' => $administrator->getKey(),
        ]);
    }

    public function medicalReport(): static
    {
        return $this->withPurpose(HelpApplicationDocumentPurpose::MedicalReport);
    }

    public function costEstimate(): static
    {
        return $this->withPurpose(HelpApplicationDocumentPurpose::CostEstimate);
    }

    public function tuitionInvoice(): static
    {
        return $this->withPurpose(HelpApplicationDocumentPurpose::TuitionInvoice);
    }

    public function admissionLetter(): static
    {
        return $this->withPurpose(HelpApplicationDocumentPurpose::AdmissionLetter);
    }

    public function other(): static
    {
        return $this->withPurpose(HelpApplicationDocumentPurpose::Other);
    }

    public function pdf(): static
    {
        return $this->withFormat('pdf', 'application/pdf');
    }

    public function jpg(): static
    {
        return $this->withFormat('jpg', 'image/jpeg');
    }

    public function png(): static
    {
        return $this->withFormat('png', 'image/png');
    }

    private function withPurpose(HelpApplicationDocumentPurpose $purpose): static
    {
        return $this->state(fn () => ['purpose' => $purpose]);
    }

    private function withFormat(string $extension, string $mimeType): static
    {
        return $this->state(fn () => [
            'original_name' => "synthetic-supporting-document.{$extension}",
            'extension' => $extension,
            'mime_type' => $mimeType,
        ])->afterMaking(function (HelpApplicationDocument $document) use ($extension): void {
            $application = HelpApplication::query()->findOrFail($document->help_application_id);
            $document->storage_path = HelpApplicationDocumentPath::make(
                $application->reference,
                $document->reference,
                $extension,
            );
        });
    }
}
