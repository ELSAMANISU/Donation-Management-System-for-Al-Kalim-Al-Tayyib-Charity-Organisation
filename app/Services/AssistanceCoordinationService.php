<?php

namespace App\Services;

use App\Enums\AssistanceCoordinationState as State;
use App\Enums\AssistanceDeliveryMethod;
use App\Enums\UserRole;
use App\Models\AssistanceCoordination;
use App\Models\AssistanceCoordinationMessage;
use App\Models\AssistanceCoordinationTransition;
use App\Models\Campaign;
use App\Models\Category;
use App\Models\HelpApplication;
use App\Models\User;
use App\Policies\AssistanceCoordinationPolicy;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AssistanceCoordinationService
{
    public const APPLICATION_FIELDS = ['id', 'reference', 'applicant_id', 'reviewed_by', 'category_id', 'status', 'open_slot', 'submitted_at', 'review_started_at', 'category_assigned_by', 'category_assigned_at', 'decided_by', 'decided_at', 'status_changed_at', 'requested_amount', 'appeal_eligibility_ended_at'];

    public const COORDINATION_FIELDS = ['id', 'reference', 'help_application_id', 'campaign_id', 'state', 'revision', 'delivery_method', 'started_by', 'started_at', 'confirmed_by', 'confirmed_at'];

    public function __construct(private readonly AssistanceCoordinationPolicy $policy, private readonly AuditLogger $audit, private readonly AssistanceCoordinationNotifications $notifications) {}

    /**
     * Lock order: Application -> Users (ascending ID) -> Campaign -> Category
     * -> Coordination -> Transition/Message -> Audit/Event/Recipient intents.
     * Publication, conversion, decisions and settlement serialize on Application first.
     * Encrypted details and message bodies are never selected by mutation/readiness locks.
     */
    private function lock(User $actor, string $applicationReference, ?string $coordinationReference, bool $administrator, bool $historical = false): array
    {
        $application = HelpApplication::query()->select(self::APPLICATION_FIELDS)
            ->selectRaw('CASE WHEN decision_note IS NOT NULL AND decision_note <> ? THEN 1 ELSE 0 END AS has_decision_note', [''])
            ->where('reference', $applicationReference)->lockForUpdate()->firstOrFail();
        // Freeze possible recipients along with the actor, without exposing account data.
        $users = User::query()->select(['id', 'role', 'is_active', 'must_change_password'])
            ->where(function ($query) use ($actor, $application) {
                $query->whereIn('id', array_filter([$actor->id, $application->applicant_id, $application->reviewed_by]))
                    ->when($application->reviewed_by === null, fn ($query) => $query->orWhere('role', UserRole::SuperAdmin->value));
            })->orderBy('id')->lockForUpdate()->get();
        $freshActor = $users->firstWhere('id', $actor->id);
        abort_unless($freshActor && ($administrator ? $this->policy->administer($freshActor, $application) : $this->policy->applicant($freshActor, $application)), 404);
        $applicant = $users->firstWhere('id', $application->applicant_id);
        abort_unless($applicant && $applicant->getRawOriginal('role') === UserRole::User->value, 404);
        $campaigns = Campaign::withTrashed()->select(['id', 'help_application_id', 'category_id', 'status', 'target_amount', 'raised_amount', 'published_at', 'funded_at', 'aid_delivery_started_at', 'completed_at', 'cancelled_at', 'deleted_at', 'created_at'])
            ->where('help_application_id', $application->id)->orderBy('id')->lockForUpdate()->get();
        abort_unless($campaigns->count() === 1, 404);
        $campaign = $campaigns->first();
        $category = Category::withTrashed()->select(['id', 'is_active', 'deleted_at'])->whereKey($campaign->category_id)->lockForUpdate()->first();
        $coordination = AssistanceCoordination::query()->select(self::COORDINATION_FIELDS)
            ->selectRaw('CASE WHEN delivery_details IS NOT NULL THEN 1 ELSE 0 END AS has_delivery_details')
            ->where('help_application_id', $application->id)->lockForUpdate()->first();
        if ($coordinationReference !== null) {
            abort_unless($coordination && $coordination->reference === $coordinationReference, 404);
        }
        abort_if($coordination && $coordination->campaign_id !== $campaign->id, 404);

        abort_unless(($historical && $this->historicallyCoherent($application, $campaign, $coordination))
            || $this->coherent($application, $campaign, $category), 404);

        return [$application, $freshActor, $campaign, $coordination, $users];
    }

    public function historicallyCoherent(HelpApplication $application, Campaign $campaign, ?AssistanceCoordination $coordination): bool
    {
        return $coordination && $coordination->getRawOriginal('state') === 'confirmed'
            && $coordination->confirmed_by !== null && $coordination->getRawOriginal('confirmed_at') !== null
            && $coordination->help_application_id === $application->id && $coordination->campaign_id === $campaign->id
            && $campaign->help_application_id === $application->id && $application->category_id === $campaign->category_id
            && match ($campaign->getRawOriginal('status')) {
                'funded' => $application->getRawOriginal('status') === 'campaign_active',
                'aid_delivery' => $application->getRawOriginal('status') === 'aid_delivery',
                'completed' => $application->getRawOriginal('status') === 'completed',
                'cancelled' => $application->getRawOriginal('status') === 'closed',
                default => false,
            };
    }

    private function coherent(HelpApplication $application, Campaign $campaign, ?Category $category): bool
    {
        try {
            return $this->inspectCoherence($application, $campaign, $category);
        } catch (InvalidFormatException|\ValueError) {
            return false;
        }
    }

    private function inspectCoherence(HelpApplication $application, Campaign $campaign, ?Category $category): bool
    {
        if ($campaign->trashed() || $campaign->getRawOriginal('status') !== 'funded'
            || $application->getRawOriginal('status') !== 'campaign_active' || $application->open_slot !== true
            || ! $category || $category->trashed() || ! $category->is_active
            || $application->category_id !== $campaign->category_id
            || $application->category_assigned_by === null || $application->decided_by === null || ! $application->has_decision_note
            || $application->appeal_eligibility_ended_at !== null) {
            return false;
        }
        $target = CampaignApplicationAmount::canonical((string) $campaign->getRawOriginal('target_amount'));
        $raised = CampaignApplicationAmount::canonical((string) $campaign->getRawOriginal('raised_amount'));
        $requested = CampaignApplicationAmount::canonical((string) $application->getRawOriginal('requested_amount'));
        if ($target === null || $raised === null || $requested === null || $target !== $raised
            || CampaignApplicationAmount::exceeds($target, $requested)) {
            return false;
        }
        foreach (['aid_delivery_started_at', 'completed_at', 'cancelled_at'] as $field) {
            if ($campaign->getRawOriginal($field) !== null) {
                return false;
            }
        }
        $times = [$application->submitted_at, $application->review_started_at, $application->category_assigned_at,
            $application->decided_at, $campaign->created_at, $campaign->published_at, $campaign->funded_at];
        foreach ($times as $index => $time) {
            if ($time === null || $time->isFuture() || ($index > 0 && $times[$index - 1]->gt($time))) {
                return false;
            }
        }

        return $application->status_changed_at !== null && $application->status_changed_at->equalTo($campaign->published_at);
    }

    public function authorize(User $actor, string $applicationReference, ?string $coordinationReference, bool $administrator): void
    {
        DB::transaction(function () use ($actor, $applicationReference, $coordinationReference, $administrator) {
            $this->lock($actor, $applicationReference, $coordinationReference, $administrator);
        });
    }

    public function detail(User $actor, string $applicationReference, ?string $coordinationReference, bool $administrator, int $page = 1): array
    {
        return DB::transaction(function () use ($actor, $applicationReference, $coordinationReference, $administrator, $page) {
            [$application, , , $coordination] = $this->lock($actor, $applicationReference, $coordinationReference, $administrator, true);
            $messages = null;
            $details = null;
            if ($coordination) {
                $messages = $coordination->messages()->select(['id', 'reference', 'coordination_id', 'sender_side', 'body', 'created_at'])
                    ->orderBy('created_at')->orderBy('id')->paginate(20, ['*'], 'page', $page);
                // lock() freshly authorizes the private participant before this separate
                // encrypted-column query decrypts details for the authorized detail page.
                if ($coordination->has_delivery_details) {
                    $details = AssistanceCoordination::query()->select(['id', 'delivery_details'])->findOrFail($coordination->id)->delivery_details;
                }
            }

            return compact('application', 'coordination', 'messages', 'details', 'administrator');
        });
    }

    public function start(User $actor, string $applicationReference): AssistanceCoordination
    {
        return DB::transaction(function () use ($actor, $applicationReference) {
            [$application, $freshActor, $campaign, $coordination, $users] = $this->lock($actor, $applicationReference, null, true);
            if ($coordination) {
                return $coordination;
            }
            $timestamp = CarbonImmutable::now(config('app.timezone'))->startOfSecond();
            $coordination = new AssistanceCoordination;
            $coordination->reference = (string) Str::uuid();
            $coordination->help_application_id = $application->id;
            $coordination->campaign_id = $campaign->id;
            $coordination->state = State::AwaitingApplicant;
            $coordination->revision = 1;
            $coordination->started_by = $freshActor->id;
            $coordination->started_at = $timestamp;
            $coordination->save();
            $this->transition($coordination, $application, $freshActor, $users, null, 'started', $timestamp);

            return $coordination;
        });
    }

    public function mutate(User $actor, string $applicationReference, string $coordinationReference, bool $administrator, string $action, array $input): void
    {
        // Revalidate scalar/UTF-8/allowlists for direct service callers too.
        abort_unless(AssistanceCoordinationInput::valid($action, $input), 404);
        DB::transaction(function () use ($actor, $applicationReference, $coordinationReference, $administrator, $action, $input) {
            [$application, $freshActor, , $coordination, $users] = $this->lock($actor, $applicationReference, $coordinationReference, $administrator);
            abort_unless($coordination->state !== State::Confirmed && (string) $coordination->revision === $input['revision'], 404);
            $timestamp = CarbonImmutable::now(config('app.timezone'))->startOfSecond();
            $old = $coordination->state;
            if ($action === 'respond') {
                abort_unless(! $administrator && in_array($old, [State::AwaitingApplicant, State::ChangesRequested], true), 404);
                $coordination->delivery_method = AssistanceDeliveryMethod::from($input['delivery_method']);
                $coordination->delivery_details = $input['delivery_details'];
                $coordination->state = State::ApplicantResponded;
            } elseif ($action === 'correct' || $action === 'confirm') {
                abort_unless($administrator && $old === State::ApplicantResponded && $coordination->has_delivery_details
                    && $coordination->delivery_method !== null && $coordination->confirmed_at === null && $coordination->confirmed_by === null, 404);
                $coordination->state = $action === 'correct' ? State::ChangesRequested : State::Confirmed;
                if ($action === 'confirm') {
                    $coordination->confirmed_by = $freshActor->id;
                    $coordination->confirmed_at = $timestamp;
                }
            } else {
                abort_unless($action === 'message', 404);
            }
            $coordination->revision++;
            $coordination->save();
            if (isset($input['body']) && $input['body'] !== '') {
                $message = new AssistanceCoordinationMessage;
                $message->reference = (string) Str::uuid();
                $message->coordination_id = $coordination->id;
                $message->sender_id = $freshActor->id;
                $message->sender_side = $administrator ? 'administrator' : 'applicant';
                $message->body = $input['body'];
                $message->created_at = $timestamp;
                $message->save();
            }
            if ($action !== 'message') {
                $this->transition($coordination, $application, $freshActor, $users, $old, match ($action) {
                    'respond' => 'response_submitted', 'correct' => 'changes_requested', 'confirm' => 'confirmed',
                }, $timestamp);
            }
            if ($action === 'message') {
                $this->notifications->recordMessage($coordination, $application, $message, $users);
            }
        });
    }

    private function transition(AssistanceCoordination $coordination, HelpApplication $application, User $actor, Collection $users, ?State $old, string $action, CarbonImmutable $timestamp): void
    {
        $transition = new AssistanceCoordinationTransition;
        $transition->reference = (string) Str::uuid();
        $transition->coordination_id = $coordination->id;
        $transition->revision = $coordination->revision;
        $transition->state = $coordination->state;
        $transition->actor_id = $actor->id;
        $transition->created_at = $timestamp;
        $transition->save();
        // Omit actor-name/contact snapshots; the immutable transition retains the actor FK.
        $this->audit->log('coordination.'.$action, subject: $coordination,
            oldValues: $old ? ['state' => $old->value] : null, newValues: ['state' => $coordination->state->value], createdAt: $timestamp);
        $this->notifications->record($coordination, $application, $transition, $users);
    }
}
