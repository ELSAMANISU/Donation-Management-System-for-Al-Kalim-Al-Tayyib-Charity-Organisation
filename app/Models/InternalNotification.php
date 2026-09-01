<?php

namespace App\Models;

use App\Enums\InternalNotificationType;
use App\Services\InternalNotificationPayload;
use Database\Factories\InternalNotificationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InternalNotification extends Model
{
    /** @use HasFactory<InternalNotificationFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected $hidden = ['event_recipient_id', 'recipient_id', 'data'];

    protected function casts(): array
    {
        return [
            'type' => InternalNotificationType::class,
            'data' => 'array',
            'read_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    public function eventRecipient(): BelongsTo
    {
        return $this->belongsTo(InternalNotificationEventRecipient::class, 'event_recipient_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    public function scopeForRecipient(Builder $query, User|int $recipient): void
    {
        $query->where('recipient_id', $recipient instanceof User ? $recipient->getKey() : $recipient);
    }

    public function scopeUnread(Builder $query): void
    {
        $query->whereNull('read_at');
    }

    public function scopeRead(Builder $query): void
    {
        $query->whereNotNull('read_at');
    }

    public function scopeNewestFirst(Builder $query): void
    {
        $query->orderByDesc('created_at')->orderByDesc('id');
    }

    /** @return array{application_reference: string, status: string} */
    public function allowlistedData(): array
    {
        return app(InternalNotificationPayload::class)->validate($this->type, $this->data);
    }
}
