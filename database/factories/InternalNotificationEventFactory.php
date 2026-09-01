<?php

namespace Database\Factories;

use App\Enums\InternalNotificationEventType;
use App\Models\HelpApplication;
use App\Models\InternalNotificationEvent;
use App\Services\InternalNotificationEventKey;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<InternalNotificationEvent> */
class InternalNotificationEventFactory extends Factory
{
    protected $model = InternalNotificationEvent::class;

    public function definition(): array
    {
        return [
            'reference' => (string) Str::uuid(),
            'type' => InternalNotificationEventType::HelpApplicationSubmitted,
            'help_application_id' => HelpApplication::factory()->pending(),
            'deduplication_key' => fn (array $attributes): string => app(InternalNotificationEventKey::class)->make(
                InternalNotificationEventType::HelpApplicationSubmitted,
                (int) $attributes['help_application_id'],
            ),
            'occurred_at' => now(),
            'projected_at' => null,
        ];
    }
}
