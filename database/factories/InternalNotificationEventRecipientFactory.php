<?php

namespace Database\Factories;

use App\Enums\InternalNotificationAudience;
use App\Enums\InternalNotificationProjectionState;
use App\Enums\InternalNotificationType;
use App\Models\InternalNotificationEvent;
use App\Models\InternalNotificationEventRecipient;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InternalNotificationEventRecipient> */
class InternalNotificationEventRecipientFactory extends Factory
{
    protected $model = InternalNotificationEventRecipient::class;

    public function definition(): array
    {
        return [
            'event_id' => InternalNotificationEvent::factory(),
            'recipient_id' => User::factory()->user(),
            'recipient_role' => fn (array $attributes): string => User::query()->findOrFail($attributes['recipient_id'])->role->value,
            'audience' => InternalNotificationAudience::Applicant,
            'notification_type' => InternalNotificationType::HelpApplicationSubmissionConfirmation,
            'state' => InternalNotificationProjectionState::Pending,
            'attempts' => 0,
            'available_at' => now(),
            'last_attempted_at' => null,
            'projected_at' => null,
        ];
    }

    public function administrator(): static
    {
        return $this->state(fn () => [
            'recipient_id' => User::factory()->admin(),
            'recipient_role' => 'admin',
            'audience' => InternalNotificationAudience::Administrator,
            'notification_type' => InternalNotificationType::HelpApplicationNewSubmission,
        ]);
    }
}
