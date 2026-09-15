<?php

namespace App\Models;

use App\Enums\AssistanceCoordinationState;
use App\Enums\AssistanceDeliveryMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssistanceCoordination extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    // A whitelist also hides any dynamically loaded relationships or selected aliases.
    protected $hidden = ['id', 'help_application_id', 'campaign_id', 'revision', 'delivery_method', 'delivery_details', 'started_by', 'confirmed_by', 'application', 'campaign', 'messages', 'transitions'];

    protected $visible = ['reference', 'state', 'started_at', 'confirmed_at'];

    protected function casts(): array
    {
        return ['state' => AssistanceCoordinationState::class, 'delivery_method' => AssistanceDeliveryMethod::class,
            'delivery_details' => 'encrypted', 'revision' => 'integer', 'started_at' => 'immutable_datetime', 'confirmed_at' => 'immutable_datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(HelpApplication::class, 'help_application_id');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AssistanceCoordinationMessage::class, 'coordination_id');
    }

    public function transitions(): HasMany
    {
        return $this->hasMany(AssistanceCoordinationTransition::class, 'coordination_id');
    }

    public function readyForFutureAidDelivery(): bool
    {
        return $this->state === AssistanceCoordinationState::Confirmed && $this->confirmed_by !== null && $this->confirmed_at !== null;
    }
}
