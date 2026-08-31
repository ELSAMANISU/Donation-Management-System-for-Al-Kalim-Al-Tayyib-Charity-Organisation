<?php

namespace App\Models;

use App\Enums\HelpApplicationDocumentPurpose;
use App\Enums\HelpApplicationDocumentSecurityStatus;
use App\Enums\HelpApplicationDocumentUploaderKind;
use Database\Factories\HelpApplicationDocumentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HelpApplicationDocument extends Model
{
    /** @use HasFactory<HelpApplicationDocumentFactory> */
    use HasFactory;

    protected $guarded = ['*'];

    protected $hidden = [
        'storage_path', 'original_name', 'extension', 'mime_type', 'size_bytes',
        'checksum', 'checksum_algorithm', 'purpose', 'uploader_kind', 'uploaded_by',
        'security_status', 'scanned_at', 'removed_at', 'removed_by',
    ];

    protected function casts(): array
    {
        return [
            'purpose' => HelpApplicationDocumentPurpose::class,
            'uploader_kind' => HelpApplicationDocumentUploaderKind::class,
            'security_status' => HelpApplicationDocumentSecurityStatus::class,
            'original_name' => 'encrypted',
            'checksum' => 'encrypted',
            'size_bytes' => 'integer',
            'scanned_at' => 'immutable_datetime',
            'removed_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(HelpApplication::class, 'help_application_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function remover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'removed_by');
    }

    public function scopeActive(Builder $query): void
    {
        $query->whereNull('removed_at');
    }

    public function scopeRemoved(Builder $query): void
    {
        $query->whereNotNull('removed_at');
    }

    public function scopeForApplication(Builder $query, HelpApplication|int $application): void
    {
        $query->where('help_application_id', $application instanceof HelpApplication ? $application->getKey() : $application);
    }

    public function scopeInUploadOrder(Builder $query): void
    {
        $query->orderBy('created_at')->orderBy('id');
    }

    public function scopeSubmissionEligibleMetadata(Builder $query): void
    {
        $query->active()->whereNotNull('purpose');

        $configuredStatuses = config('help_application_documents.submission_eligible_security_statuses');
        $allowedStatuses = [
            HelpApplicationDocumentSecurityStatus::AcceptedUnscanned->value,
            HelpApplicationDocumentSecurityStatus::Clean->value,
        ];

        if (! is_array($configuredStatuses) || ! array_is_list($configuredStatuses)) {
            $query->whereRaw('0 = 1');

            return;
        }

        $eligibleStatuses = [];

        foreach ($configuredStatuses as $status) {
            if (! is_string($status) || ! in_array($status, $allowedStatuses, true)) {
                $query->whereRaw('0 = 1');

                return;
            }

            $eligibleStatuses[$status] = $status;
        }

        $query->whereIn('security_status', array_values($eligibleStatuses));
    }
}
