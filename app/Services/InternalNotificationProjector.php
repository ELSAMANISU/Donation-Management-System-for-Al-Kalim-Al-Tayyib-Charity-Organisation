<?php

namespace App\Services;

use App\Data\InternalNotificationProjectionResult;
use App\Enums\InternalNotificationAudience;
use App\Enums\InternalNotificationProjectionState;
use App\Enums\InternalNotificationType;
use App\Enums\UserRole;
use App\Models\AssistanceCoordination;
use App\Models\InternalNotification;
use App\Models\InternalNotificationEvent;
use App\Models\InternalNotificationEventRecipient;
use App\Policies\AssistanceCoordinationPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

final class InternalNotificationProjector
{
    private const ABSOLUTE_MAXIMUM_BATCH_SIZE = 1000;

    public function __construct(private readonly InternalNotificationPayload $payload) {}

    public function projectEvent(InternalNotificationEvent|int $event, ?int $limit = null): InternalNotificationProjectionResult
    {
        [$batchLimit, $retryDelay] = $this->projectionConfiguration($limit);
        $eventId = $event instanceof InternalNotificationEvent ? $event->getKey() : $event;

        if (! is_int($eventId) || $eventId < 1) {
            throw new InvalidArgumentException('Internal notification event is invalid.');
        }

        if (! InternalNotificationEvent::query()->whereKey($eventId)->exists()) {
            throw new InvalidArgumentException('Internal notification event is invalid.');
        }

        $ids = InternalNotificationEventRecipient::query()
            ->where('event_id', $eventId)
            ->readyForProjection()
            ->orderBy('id')
            ->limit($batchLimit)
            ->pluck('id');

        return $this->projectIds($ids->all(), $retryDelay, $eventId);
    }

    public function projectReady(?int $limit = null): InternalNotificationProjectionResult
    {
        [$batchLimit, $retryDelay] = $this->projectionConfiguration($limit);
        $ids = InternalNotificationEventRecipient::query()
            ->readyForProjection()
            ->orderBy('id')
            ->limit($batchLimit)
            ->pluck('id');

        return $this->projectIds($ids->all(), $retryDelay);
    }

    /** @param list<int> $ids */
    private function projectIds(array $ids, int $retryDelay, ?int $remainingEventId = null): InternalNotificationProjectionResult
    {
        $projected = $cancelled = $failed = 0;

        foreach ($ids as $id) {
            try {
                $outcome = $this->projectOne($id);
                $projected += $outcome === InternalNotificationProjectionState::Projected ? 1 : 0;
                $cancelled += $outcome === InternalNotificationProjectionState::Cancelled ? 1 : 0;
            } catch (Throwable) {
                $failed++;
                $this->recordFailure($id, $retryDelay);
                $this->warnSafely();
            }
        }

        $remaining = InternalNotificationEventRecipient::query()->unfinished();

        if ($remainingEventId !== null) {
            $remaining->where('event_id', $remainingEventId);
        }

        return new InternalNotificationProjectionResult(
            projected: $projected,
            cancelled: $cancelled,
            failed: $failed,
            remaining: $remaining->count(),
        );
    }

    private function projectOne(int $intentId): ?InternalNotificationProjectionState
    {
        return DB::transaction(function () use ($intentId): ?InternalNotificationProjectionState {
            $intent = InternalNotificationEventRecipient::query()->lockForUpdate()->findOrFail($intentId);

            if ($intent->state !== InternalNotificationProjectionState::Pending) {
                return null;
            }

            $attemptedAt = now();
            $intent->attempts++;
            $intent->last_attempted_at = $attemptedAt;
            $event = InternalNotificationEvent::query()->lockForUpdate()->findOrFail($intent->event_id);
            $coordinationEvent = $this->payload->isCoordination($intent->notification_type);
            $application = $event->application()->select($coordinationEvent
                ? ['id', 'reference', 'applicant_id', 'reviewed_by'] : ['id', 'reference'])->firstOrFail();
            $recipient = $intent->recipient_id === null ? null : $intent->recipient()->select($coordinationEvent
                ? ['id', 'role', 'is_active', 'must_change_password'] : ['*'])->first();

            if ($recipient === null) {
                $intent->state = InternalNotificationProjectionState::Cancelled;
                $intent->projected_at = $attemptedAt;
                $intent->save();
                $this->finishEventIfTerminal($event, $attemptedAt);

                return InternalNotificationProjectionState::Cancelled;
            }

            if ($coordinationEvent) {
                $linked = $application;
                $coordination = AssistanceCoordination::query()->select(['id', 'help_application_id'])
                    ->where('reference', $event->coordination_reference)->first();
                $policy = app(AssistanceCoordinationPolicy::class);
                $eligible = $linked && $coordination && $coordination->help_application_id === $linked->id && $recipient
                    && ($intent->audience === InternalNotificationAudience::Applicant
                        ? $policy->applicant($recipient, $linked)
                        : ($policy->administer($recipient, $linked) && ($linked->reviewed_by === null
                            ? $recipient->hasRole(UserRole::SuperAdmin) : $recipient->id === $linked->reviewed_by)));
                if (! $eligible) {
                    $intent->state = InternalNotificationProjectionState::Cancelled;
                    $intent->projected_at = $attemptedAt;
                    $intent->save();
                    $this->finishEventIfTerminal($event, $attemptedAt);

                    return InternalNotificationProjectionState::Cancelled;
                }
            }
            $data = $this->payload->build($intent->notification_type, $coordinationEvent ? $event->coordination_reference : ($intent->notification_type === InternalNotificationType::CampaignFundingCompleted ? $event->reference : $application->reference));
            $notification = InternalNotification::query()->where('event_recipient_id', $intent->getKey())->first();

            if ($notification === null) {
                $notification = new InternalNotification;
                $notification->reference = (string) Str::uuid();
                $notification->event_recipient_id = $intent->getKey();
                $notification->recipient_id = $intent->recipient_id;
                $notification->type = $intent->notification_type;
                $notification->data = $data;
                $notification->read_at = null;
                $notification->created_at = $coordinationEvent ? $event->occurred_at : $attemptedAt;
                $notification->save();
            }

            $intent->state = InternalNotificationProjectionState::Projected;
            $intent->projected_at = $attemptedAt;
            $intent->save();
            $this->finishEventIfTerminal($event, $attemptedAt);

            return InternalNotificationProjectionState::Projected;
        });
    }

    private function finishEventIfTerminal(InternalNotificationEvent $event, mixed $projectedAt): void
    {
        if ($event->projected_at === null && ! $event->recipientIntents()->unfinished()->exists()) {
            $event->projected_at = $projectedAt;
            $event->save();
        }
    }

    private function recordFailure(int $intentId, int $retryDelay): void
    {
        try {
            DB::transaction(function () use ($intentId, $retryDelay): void {
                $intent = InternalNotificationEventRecipient::query()->lockForUpdate()->find($intentId);

                if ($intent === null || $intent->state !== InternalNotificationProjectionState::Pending) {
                    return;
                }

                $attemptedAt = now();
                $intent->attempts++;
                $intent->last_attempted_at = $attemptedAt;
                $intent->available_at = $attemptedAt->copy()->addSeconds($retryDelay);
                $intent->save();
            });
        } catch (Throwable) {
            // Preserve the original recoverable database state.
        }
    }

    private function warnSafely(): void
    {
        try {
            Log::warning('Internal notification projection failed.');
        } catch (Throwable) {
        }
    }

    /** @return array{int, int} */
    private function projectionConfiguration(?int $limit): array
    {
        $maximum = config('internal_notifications.projection.maximum_batch_size');
        $default = config('internal_notifications.projection.default_batch_size');
        $retryDelay = config('internal_notifications.projection.retry_delay_seconds');
        $selected = $limit ?? $default;

        if (! is_int($maximum)
            || $maximum < 1
            || $maximum > self::ABSOLUTE_MAXIMUM_BATCH_SIZE
            || ! is_int($selected)
            || $selected < 1
            || $selected > $maximum
            || ! is_int($retryDelay)
            || $retryDelay < 1
            || $retryDelay > 86400) {
            throw new InvalidArgumentException('Internal notification projection limit is invalid.');
        }

        return [$selected, $retryDelay];
    }
}
