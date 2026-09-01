<?php

namespace Database\Factories;

use App\Enums\InternalNotificationProjectionState;
use App\Models\InternalNotification;
use App\Models\InternalNotificationEventRecipient;
use App\Services\InternalNotificationPayload;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<InternalNotification> */
class InternalNotificationFactory extends Factory
{
    protected $model = InternalNotification::class;

    public function configure(): static
    {
        return $this->afterCreating(function (InternalNotification $notification): void {
            $intent = $notification->eventRecipient;
            $projectedAt = $notification->created_at;
            $intent->state = InternalNotificationProjectionState::Projected;
            $intent->projected_at = $projectedAt;
            $intent->save();

            if (! $intent->event->recipientIntents()->unfinished()->exists()) {
                $event = $intent->event;
                $event->projected_at = $projectedAt;
                $event->save();
            }
        });
    }

    public function definition(): array
    {
        return [
            'reference' => (string) Str::uuid(),
            'event_recipient_id' => InternalNotificationEventRecipient::factory(),
            'recipient_id' => fn (array $attributes): ?int => InternalNotificationEventRecipient::query()->findOrFail($attributes['event_recipient_id'])->recipient_id,
            'type' => fn (array $attributes) => InternalNotificationEventRecipient::query()->findOrFail($attributes['event_recipient_id'])->notification_type,
            'data' => function (array $attributes): array {
                $intent = InternalNotificationEventRecipient::query()->findOrFail($attributes['event_recipient_id']);

                return app(InternalNotificationPayload::class)->build($intent->notification_type, $intent->event->application->reference);
            },
            'read_at' => null,
        ];
    }
}
