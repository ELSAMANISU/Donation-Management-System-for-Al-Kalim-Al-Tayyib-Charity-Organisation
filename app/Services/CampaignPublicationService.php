<?php

namespace App\Services;

use App\Enums\CampaignStatus;
use App\Enums\HelpApplicationStatus;
use App\Enums\InternalNotificationAudience;
use App\Enums\InternalNotificationEventType;
use App\Enums\InternalNotificationProjectionState;
use App\Enums\InternalNotificationType;
use App\Enums\UserRole;
use App\Models\Campaign;
use App\Models\Category;
use App\Models\HelpApplication;
use App\Models\InternalNotificationEvent;
use App\Models\InternalNotificationEventRecipient;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class CampaignPublicationService
{
    public const SUCCESS = 'Campaign published successfully. / تم نشر الحملة بنجاح.';

    private const APPLICATION_FIELDS = ['id', 'reference', 'applicant_id', 'category_id', 'requested_amount', 'status', 'open_slot', 'reviewed_by', 'submitted_at', 'review_started_at', 'decided_by', 'decided_at', 'status_changed_at', 'appeal_eligibility_ended_at', 'category_assigned_by', 'category_assigned_at'];

    public function __construct(private readonly AuditLogger $auditLogger, private readonly CampaignPublicationReadiness $readiness, private readonly InternalNotificationEventKey $eventKey, private readonly InternalNotificationProjector $projector) {}

    public function missing(User $actor, Campaign $campaign): array
    {
        return DB::transaction(function () use ($actor, $campaign): array {
            [$lockedActor, $lockedCampaign, $application, $category] = $this->lock($actor, $campaign);
            $missing = $this->readiness->missing($lockedCampaign, $category);
            if ($application && ! $this->readiness->linkedReady($application, $lockedCampaign)) {
                $missing[] = 'application';
            }

            return $missing;
        });
    }

    public function publish(User $actor, Campaign $campaign, CarbonImmutable $expiresAt): Campaign
    {
        return DB::transaction(function () use ($actor, $campaign, $expiresAt): Campaign {
            [$lockedActor, $lockedCampaign, $application, $category] = $this->lock($actor, $campaign);
            abort_unless($this->readiness->missing($lockedCampaign, $category) === [], 404);
            abort_if($application && ! $this->readiness->linkedReady($application, $lockedCampaign), 404);
            $timestamp = CarbonImmutable::now(config('app.timezone'));
            abort_unless($expiresAt->gt($timestamp), 404);
            $timestamp = $timestamp->startOfSecond();
            $lockedCampaign->status = CampaignStatus::Active;
            $lockedCampaign->expires_at = $expiresAt;
            $lockedCampaign->published_at = $timestamp;
            $lockedCampaign->updated_by = $lockedActor->id;
            $lockedCampaign->updated_at = $timestamp;
            $lockedCampaign->timestamps = false;
            $lockedCampaign->save();
            $this->auditLogger->log('campaign.published', actor: $lockedActor, subject: $lockedCampaign,
                oldValues: ['status' => 'draft', 'published_at' => null, 'expires_at' => null],
                newValues: ['status' => 'active', 'published_at' => $timestamp->toISOString(), 'expires_at' => $expiresAt->toISOString()]);
            if ($application) {
                $application->status = HelpApplicationStatus::CampaignActive;
                $application->status_changed_at = $application->updated_at = $timestamp;
                $application->updated_by = $lockedActor->id;
                $application->timestamps = false;
                $application->save();
                $this->auditLogger->log('help_application.campaign_activated', actor: $lockedActor, subject: $application,
                    oldValues: ['status' => 'converted_to_campaign'], newValues: ['status' => 'campaign_active']);
                $this->notify($application, $timestamp);
            }

            return $lockedCampaign;
        });
    }

    private function lock(User $actor, Campaign $campaign): array
    {
        // Same order as editing: linked application, actor, Campaign, Category.
        // Conversion also locks the application first, serializing linked operations
        // before its Category/Campaign relationship check can conflict with this order.
        $application = $campaign->help_application_id === null ? null : HelpApplication::query()
            ->select(self::APPLICATION_FIELDS)->selectRaw('CASE WHEN decision_note IS NOT NULL AND decision_note <> ? THEN 1 ELSE 0 END AS has_decision_note', [''])
            ->whereKey($campaign->help_application_id)->lockForUpdate()->firstOrFail();
        $lockedActor = User::query()->select(['id', 'name', 'role', 'is_active', 'must_change_password'])->whereKey($actor->id)->lockForUpdate()->firstOrFail();
        $lockedCampaign = Campaign::query()->whereKey($campaign->id)->lockForUpdate()->firstOrFail();
        abort_unless(Gate::forUser($lockedActor)->allows('publish', $lockedCampaign), 404);
        abort_unless($lockedCampaign->help_application_id === $campaign->help_application_id && $lockedCampaign->slug === $campaign->slug, 404);
        if ($application) {
            $related = Campaign::withTrashed()->select(['id'])->where('help_application_id', $application->id)->orderBy('id')->lockForUpdate()->get();
            abort_unless($related->count() === 1 && $related->first()->id === $lockedCampaign->id, 404);
        }
        $category = Category::query()->select(['id', 'is_active', 'deleted_at'])->whereKey($lockedCampaign->category_id)->lockForUpdate()->first();

        return [$lockedActor, $lockedCampaign, $application, $category];
    }

    private function notify(HelpApplication $application, CarbonImmutable $timestamp): void
    {
        $event = new InternalNotificationEvent;
        $event->reference = (string) Str::uuid();
        $event->type = InternalNotificationEventType::HelpApplicationCampaignActivated;
        $event->help_application_id = $application->id;
        $event->deduplication_key = $this->eventKey->make($event->type, $application->id);
        $event->occurred_at = $timestamp;
        $event->projected_at = null;
        $event->save();
        $intent = new InternalNotificationEventRecipient;
        $intent->event_id = $event->id;
        $intent->recipient_id = $application->applicant_id;
        $intent->recipient_role = UserRole::User;
        $intent->audience = InternalNotificationAudience::Applicant;
        $intent->notification_type = InternalNotificationType::HelpApplicationCampaignActivated;
        $intent->state = InternalNotificationProjectionState::Pending;
        $intent->attempts = 0;
        $intent->available_at = $timestamp;
        $intent->save();
        DB::afterCommit(function () use ($event): void {
            try {
                $this->projector->projectEvent($event->id);
            } catch (Throwable) {
                try {
                    Log::warning('Campaign activation notification projection could not be completed.');
                } catch (Throwable) {
                }
            }
        });
    }
}
