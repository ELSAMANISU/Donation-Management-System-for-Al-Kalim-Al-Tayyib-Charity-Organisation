<?php

namespace App\Models;

use App\Enums\AssistanceCoordinationState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class AssistanceCoordinationTransition extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected $hidden = ['id', 'coordination_id', 'actor_id', 'revision', 'coordination', 'actor'];

    protected $visible = ['reference', 'state', 'created_at'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Coordination transitions are immutable.'));
        static::deleting(fn () => throw new LogicException('Coordination transitions are immutable.'));
    }

    protected function casts(): array
    {
        return ['state' => AssistanceCoordinationState::class, 'created_at' => 'immutable_datetime'];
    }

    public function coordination(): BelongsTo
    {
        return $this->belongsTo(AssistanceCoordination::class, 'coordination_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
