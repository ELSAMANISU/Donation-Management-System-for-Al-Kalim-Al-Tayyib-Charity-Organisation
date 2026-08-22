<?php

namespace App\Models;

use Database\Factories\AuditLogFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

class AuditLog extends Model
{
    /** @use HasFactory<AuditLogFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * Audit attributes must be assigned explicitly by the audit logger.
     *
     * @var array<int, string>
     */
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Audit records are append-only and cannot be updated through model instances.
     */
    protected function performUpdate(Builder $query): bool
    {
        throw new LogicException('Audit log entries are append-only and cannot be updated.');
    }

    /**
     * Audit records are append-only and cannot be deleted through model instances.
     */
    protected function performDeleteOnModel(): void
    {
        throw new LogicException('Audit log entries are append-only and cannot be deleted.');
    }
}
