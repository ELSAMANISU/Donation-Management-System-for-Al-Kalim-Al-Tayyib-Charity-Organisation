<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class AssistanceCoordinationMessage extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected $hidden = ['id', 'coordination_id', 'sender_id', 'body', 'coordination', 'sender'];

    protected $visible = ['reference', 'sender_side', 'created_at'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Coordination messages are immutable.'));
        static::deleting(fn () => throw new LogicException('Coordination messages are immutable.'));
    }

    protected function casts(): array
    {
        return ['body' => 'encrypted', 'created_at' => 'immutable_datetime'];
    }

    public function coordination(): BelongsTo
    {
        return $this->belongsTo(AssistanceCoordination::class, 'coordination_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }
}
