<?php

namespace App\Models;

use App\Enums\AidDeliveryState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AidDelivery extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected $hidden = ['id', 'coordination_id', 'amount', 'currency', 'entry_key', 'started_by', 'revision', 'unfinished_coordination_id', 'coordination', 'transitions', 'proof'];

    protected $visible = ['reference', 'state', 'started_at', 'completed_at'];

    protected function casts(): array
    {
        return ['state' => AidDeliveryState::class, 'amount' => 'decimal:2', 'revision' => 'integer', 'started_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    public function coordination(): BelongsTo
    {
        return $this->belongsTo(AssistanceCoordination::class, 'coordination_id');
    }

    public function transitions(): HasMany
    {
        return $this->hasMany(AidDeliveryTransition::class, 'delivery_id');
    }

    public function proof(): HasOne
    {
        return $this->hasOne(AidDeliveryProof::class, 'delivery_id');
    }
}
