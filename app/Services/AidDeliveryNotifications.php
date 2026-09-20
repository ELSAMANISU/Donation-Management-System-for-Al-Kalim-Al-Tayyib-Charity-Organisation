<?php

namespace App\Services;

use App\Enums\InternalNotificationAudience;
use App\Enums\InternalNotificationEventType;
use App\Enums\InternalNotificationProjectionState;
use App\Enums\InternalNotificationType;
use App\Models\AidDelivery;
use App\Models\AidDeliveryTransition;
use App\Models\HelpApplication;
use App\Models\InternalNotificationEvent;
use App\Models\InternalNotificationEventRecipient;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class AidDeliveryNotifications
{
    public function __construct(private readonly InternalNotificationProjector $projector) {}

    public function record(AidDelivery $delivery, HelpApplication $application, AidDeliveryTransition $transition, Collection $users): void
    {
        $type = InternalNotificationType::from('aid_delivery_'.$transition->action->value);
        $immutableReference = $transition->reference;
        $timestamp = $transition->created_at;
        $key = hash('sha256', $type->value.':'.$immutableReference);
        if (InternalNotificationEvent::where('deduplication_key', $key)->lockForUpdate()->exists()) {
            return;
        }
        $recipients = $users->filter(fn ($user) => $user->id === $application->applicant_id
            && $user->getRawOriginal('role') === 'user' && $user->is_active && ! $user->must_change_password);
        $event = new InternalNotificationEvent;
        $event->reference = (string) Str::uuid();
        $event->type = InternalNotificationEventType::from($type->value);
        $event->help_application_id = $application->id;
        $event->delivery_reference = $delivery->reference;
        $event->deduplication_key = $key;
        $event->occurred_at = $timestamp;
        $event->created_at = $timestamp;
        $event->save();
        foreach ($recipients as $user) {
            $intent = new InternalNotificationEventRecipient;
            $intent->event_id = $event->id;
            $intent->recipient_id = $user->id;
            $intent->recipient_role = $user->role;
            $intent->audience = InternalNotificationAudience::Applicant;
            $intent->notification_type = $type;
            $intent->state = InternalNotificationProjectionState::Pending;
            $intent->attempts = 0;
            $intent->available_at = $timestamp;
            $intent->created_at = $timestamp;
            $intent->save();
        }
        // An event with no eligible recipients is already terminal.
        if ($recipients->isEmpty()) {
            $event->projected_at = $timestamp;
            $event->save();
        }
        DB::afterCommit(function () use ($event): void {
            try {
                $this->projector->projectEvent($event->id);
            } catch (Throwable) {
                try {
                    Log::warning('Delivery notification projection could not be completed.');
                } catch (Throwable) {
                }
            }
        });
    }
}
