<?php

namespace App\Models;

use App\Enums\InternalNotificationEventType;
use Database\Factories\InternalNotificationEventFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InternalNotificationEvent extends Model
{
    /** @use HasFactory<InternalNotificationEventFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected $hidden = ['deduplication_key'];

    protected function casts(): array
    {
        return [
            'type' => InternalNotificationEventType::class,
            'occurred_at' => 'immutable_datetime',
            'projected_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(HelpApplication::class, 'help_application_id');
    }

    public function recipientIntents(): HasMany
    {
        return $this->hasMany(InternalNotificationEventRecipient::class, 'event_id');
    }

    public function scopeReadyForProjection(Builder $query): void
    {
        $query->whereNull('projected_at')->whereHas('recipientIntents', fn (Builder $intent) => $intent->readyForProjection());
    }

    public function scopeUnfinished(Builder $query): void
    {
        $query->whereNull('projected_at');
    }
}
