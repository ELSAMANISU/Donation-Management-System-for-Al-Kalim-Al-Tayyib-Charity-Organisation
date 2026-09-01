<?php

namespace App\Models;

use App\Enums\InternalNotificationAudience;
use App\Enums\InternalNotificationProjectionState;
use App\Enums\InternalNotificationType;
use App\Enums\UserRole;
use Database\Factories\InternalNotificationEventRecipientFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class InternalNotificationEventRecipient extends Model
{
    /** @use HasFactory<InternalNotificationEventRecipientFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected $hidden = ['event_id', 'recipient_id', 'attempts', 'available_at', 'last_attempted_at'];

    protected function casts(): array
    {
        return [
            'recipient_role' => UserRole::class,
            'audience' => InternalNotificationAudience::class,
            'notification_type' => InternalNotificationType::class,
            'state' => InternalNotificationProjectionState::class,
            'attempts' => 'integer',
            'available_at' => 'immutable_datetime',
            'last_attempted_at' => 'immutable_datetime',
            'projected_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(InternalNotificationEvent::class, 'event_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    public function internalNotification(): HasOne
    {
        return $this->hasOne(InternalNotification::class, 'event_recipient_id');
    }

    public function scopeReadyForProjection(Builder $query): void
    {
        $query->where('state', InternalNotificationProjectionState::Pending)->where('available_at', '<=', now());
    }

    public function scopeUnfinished(Builder $query): void
    {
        $query->where('state', InternalNotificationProjectionState::Pending);
    }
}
