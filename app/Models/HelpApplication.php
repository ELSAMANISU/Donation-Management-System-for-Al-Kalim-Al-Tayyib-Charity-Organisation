<?php

namespace App\Models;

use App\Enums\HelpApplicationStatus;
use App\Enums\IdentityDocumentType;
use App\Enums\PublicIdentityPreference;
use Database\Factories\HelpApplicationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HelpApplication extends Model
{
    /** @use HasFactory<HelpApplicationFactory> */
    use HasFactory;

    public const CONSENT_VERSION = 'help_application_v1';

    /** @var array<int, string> */
    protected $guarded = ['*'];

    /** @var list<string> */
    protected $hidden = [
        'full_name',
        'email',
        'phone',
        'address',
        'date_of_birth',
        'identity_document_type',
        'identity_issuing_country',
        'identity_document_number',
        'identity_blind_index',
        'identity_blind_index_version',
        'requested_amount',
        'private_story',
        'preferred_receiving_method',
        'public_identity_preference',
        'consent_version',
        'consented_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => HelpApplicationStatus::class,
            'open_slot' => 'boolean',
            'full_name' => 'encrypted',
            'email' => 'encrypted',
            'phone' => 'encrypted',
            'address' => 'encrypted',
            'date_of_birth' => 'encrypted',
            'identity_document_type' => IdentityDocumentType::class,
            'identity_document_number' => 'encrypted',
            'identity_blind_index_version' => 'integer',
            'requested_amount' => 'decimal:2',
            'private_story' => 'encrypted',
            'preferred_receiving_method' => 'encrypted',
            'public_identity_preference' => PublicIdentityPreference::class,
            'consented_at' => 'immutable_datetime',
            'category_assigned_at' => 'immutable_datetime',
            'review_started_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
            'submitted_at' => 'immutable_datetime',
            'status_changed_at' => 'immutable_datetime',
            'appeal_eligibility_ended_at' => 'immutable_datetime',
        ];
    }

    /** @return Attribute<string|null, string|null> */
    protected function identityIssuingCountry(): Attribute
    {
        return Attribute::set(fn (?string $value): ?string => $value === null
            ? null
            : mb_strtoupper(trim($value), 'UTF-8'));
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applicant_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class)->withTrashed();
    }

    public function categoryAssigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'category_assigned_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function decisionMaker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(HelpApplicationDocument::class);
    }

    /** @param Builder<HelpApplication> $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', array_map(
            fn (HelpApplicationStatus $status): string => $status->value,
            array_filter(HelpApplicationStatus::cases(), fn (HelpApplicationStatus $status): bool => $status->isOpen()),
        ));
    }

    /** @param Builder<HelpApplication> $query */
    public function scopeTerminal(Builder $query): void
    {
        $query->whereIn('status', array_map(
            fn (HelpApplicationStatus $status): string => $status->value,
            array_filter(HelpApplicationStatus::cases(), fn (HelpApplicationStatus $status): bool => $status->isTerminal()),
        ));
    }

    /** @param Builder<HelpApplication> $query */
    public function scopeForApplicant(Builder $query, User|int $applicant): void
    {
        $query->where('applicant_id', $applicant instanceof User ? $applicant->getKey() : $applicant);
    }

    /** @param Builder<HelpApplication> $query */
    public function scopeInStatus(Builder $query, HelpApplicationStatus $status): void
    {
        $query->where('status', $status);
    }

    /** @param Builder<HelpApplication> $query */
    public function scopeInReviewOrder(Builder $query): void
    {
        $query->whereNotNull('submitted_at')->orderBy('submitted_at')->orderBy('id');
    }
}
