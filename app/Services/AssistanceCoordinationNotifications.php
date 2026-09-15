<?php

namespace App\Services;

use App\Enums\AssistanceCoordinationState as State;
use App\Enums\InternalNotificationAudience;
use App\Enums\InternalNotificationEventType;
use App\Enums\InternalNotificationProjectionState;
use App\Enums\InternalNotificationType;
use App\Enums\UserRole;
use App\Models\AssistanceCoordination;
use App\Models\AssistanceCoordinationMessage;
use App\Models\AssistanceCoordinationTransition;
use App\Models\HelpApplication;
use App\Models\InternalNotificationEvent;
use App\Models\InternalNotificationEventRecipient;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class AssistanceCoordinationNotifications
{
    public function __construct(private readonly InternalNotificationProjector $projector) {}

    public function record(AssistanceCoordination $coordination, HelpApplication $application, AssistanceCoordinationTransition $transition, Collection $users): void
    {
        $type = match ($coordination->state) {
            State::AwaitingApplicant => InternalNotificationType::CoordinationStarted,
            State::ApplicantResponded => InternalNotificationType::CoordinationResponseSubmitted,
            State::ChangesRequested => InternalNotificationType::CoordinationChangesRequested,
            State::Confirmed => InternalNotificationType::CoordinationConfirmed,
        };
        $this->recordAvailable($coordination, $application, $users, $type, $coordination->state === State::ApplicantResponded, $transition->reference, $transition->created_at);
    }

    public function recordMessage(AssistanceCoordination $coordination, HelpApplication $application, AssistanceCoordinationMessage $message, Collection $users): void
    {
        $this->recordAvailable($coordination, $application, $users, InternalNotificationType::CoordinationMessageAvailable, $message->sender_side === 'applicant', $message->reference, $message->created_at);
    }

    private function recordAvailable(AssistanceCoordination $coordination, HelpApplication $application, Collection $users, InternalNotificationType $type, bool $administratorEvent, string $immutableReference, CarbonImmutable $timestamp): void
    {
        $key = hash('sha256', $type->value.':'.$immutableReference);
        if (InternalNotificationEvent::where('deduplication_key', $key)->lockForUpdate()->exists()) {
            return;
        }
        $recipients = $users->filter(function ($user) use ($application, $administratorEvent) {
            if (UserRole::tryFrom((string) $user->getRawOriginal('role')) === null || ! $user->is_active || $user->must_change_password) {
                return false;
            }
            if (! $administratorEvent) {
                return $user->id === $application->applicant_id && $user->hasRole(UserRole::User);
            }

            return $application->reviewed_by === null ? $user->hasRole(UserRole::SuperAdmin)
                : ($user->id === $application->reviewed_by && $user->hasAnyRole([UserRole::Admin, UserRole::SuperAdmin]));
        });
        $event = new InternalNotificationEvent;
        $event->reference = (string) Str::uuid();
        $event->type = InternalNotificationEventType::from($type->value);
        $event->help_application_id = $application->id;
        $event->coordination_reference = $coordination->reference;
        $event->deduplication_key = $key;
        $event->occurred_at = $timestamp;
        $event->created_at = $timestamp;
        $event->save();
        foreach ($recipients as $user) {
            $intent = new InternalNotificationEventRecipient;
            $intent->event_id = $event->id;
            $intent->recipient_id = $user->id;
            $intent->recipient_role = $user->role;
            $intent->audience = $administratorEvent ? InternalNotificationAudience::Administrator : InternalNotificationAudience::Applicant;
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
                    Log::warning('Coordination notification projection could not be completed.');
                } catch (Throwable) {
                }
            }
        });
    }
}
