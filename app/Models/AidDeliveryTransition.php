<?php

namespace App\Models;

use App\Enums\AidDeliveryAction;
use App\Enums\AidDeliveryState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class AidDeliveryTransition extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected $hidden = ['id', 'delivery_id', 'actor_id', 'revision', 'note', 'delivery', 'actor'];

    protected $visible = ['reference', 'state', 'action', 'created_at'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Delivery transitions are immutable.'));
        static::deleting(fn () => throw new LogicException('Delivery transitions are immutable.'));
    }

    protected function casts(): array
    {
        return ['state' => AidDeliveryState::class, 'action' => AidDeliveryAction::class, 'revision' => 'integer', 'note' => 'encrypted', 'created_at' => 'immutable_datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(AidDelivery::class, 'delivery_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
