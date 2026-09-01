<?php

namespace App\Models;

use App\Enums\HelpApplicationDuplicateWarningStatus;
use Database\Factories\HelpApplicationDuplicateWarningFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class HelpApplicationDuplicateWarning extends Model
{
    /** @use HasFactory<HelpApplicationDuplicateWarningFactory> */
    use HasFactory;

    protected $guarded = ['*'];

    protected $hidden = ['submitted_application_id', 'matched_application_id', 'resolved_by', 'resolution_note'];

    protected static function booted(): void
    {
        static::saving(function (self $warning): void {
            if ((int) $warning->submitted_application_id === (int) $warning->matched_application_id) {
                throw new LogicException('A duplicate warning cannot match an application to itself.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'status' => HelpApplicationDuplicateWarningStatus::class,
            'resolution_note' => 'encrypted',
            'resolved_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    public function submittedApplication(): BelongsTo
    {
        return $this->belongsTo(HelpApplication::class, 'submitted_application_id');
    }

    public function matchedApplication(): BelongsTo
    {
        return $this->belongsTo(HelpApplication::class, 'matched_application_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function scopeUnreviewed(Builder $query): void
    {
        $query->where('status', HelpApplicationDuplicateWarningStatus::Unreviewed);
    }

    public function scopeForSubmittedApplication(Builder $query, HelpApplication|int $application): void
    {
        $query->where('submitted_application_id', $application instanceof HelpApplication ? $application->getKey() : $application);
    }

    public function scopeForMatchedApplication(Builder $query, HelpApplication|int $application): void
    {
        $query->where('matched_application_id', $application instanceof HelpApplication ? $application->getKey() : $application);
    }

    public function scopeInAdministratorOrder(Builder $query): void
    {
        $query->orderBy('created_at')->orderBy('id');
    }
}
