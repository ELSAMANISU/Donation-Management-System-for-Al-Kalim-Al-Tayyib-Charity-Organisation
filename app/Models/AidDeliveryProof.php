<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class AidDeliveryProof extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected $hidden = ['id', 'delivery_id', 'sandbox_reference', 'generator', 'version', 'delivery'];

    protected $visible = ['reference', 'created_at'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Sandbox proofs are immutable.'));
        static::deleting(fn () => throw new LogicException('Sandbox proofs are immutable.'));
    }

    protected function casts(): array
    {
        return ['version' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(AidDelivery::class, 'delivery_id');
    }
}
