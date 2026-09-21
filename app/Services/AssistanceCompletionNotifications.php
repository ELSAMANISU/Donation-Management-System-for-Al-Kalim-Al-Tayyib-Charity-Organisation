<?php

namespace App\Services;

use App\Enums\InternalNotificationAudience;
use App\Enums\InternalNotificationEventType;
use App\Enums\InternalNotificationProjectionState;
use App\Enums\InternalNotificationType;
use App\Models\HelpApplication;
use App\Models\InternalNotificationEvent;
use App\Models\InternalNotificationEventRecipient;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class AssistanceCompletionNotifications
{
    public function __construct(private readonly InternalNotificationProjector $projector) {}

    public function record(HelpApplication $application, Collection $users, CarbonImmutable $timestamp): void
    {
        $event = new InternalNotificationEvent;
        $event->reference = (string) Str::uuid();
        $event->type = InternalNotificationEventType::HelpApplicationCompleted;
        $event->help_application_id = $application->id;
        $event->deduplication_key = hash('sha256', 'help_application.completed:'.$application->id);
        $event->occurred_at = $event->created_at = $timestamp;
        $event->save();
        $owner = $users->firstWhere('id', $application->applicant_id);
        if ($owner && $owner->getRawOriginal('role') === 'user' && $owner->is_active && ! $owner->must_change_password) {
            $intent = new InternalNotificationEventRecipient;
            $intent->event_id = $event->id;
            $intent->recipient_id = $owner->id;
            $intent->recipient_role = $owner->role;
            $intent->audience = InternalNotificationAudience::Applicant;
            $intent->notification_type = InternalNotificationType::HelpApplicationCompleted;
            $intent->state = InternalNotificationProjectionState::Pending;
            $intent->attempts = 0;
            $intent->available_at = $intent->created_at = $timestamp;
            $intent->save();
        } else {
            $event->projected_at = $timestamp;
            $event->save();
        }
        DB::afterCommit(function () use ($event): void {
            try {
                $this->projector->projectEvent($event->id);
            } catch (Throwable) {
                Log::warning('Completion notification projection could not be completed.');
            }
        });
    }
}
