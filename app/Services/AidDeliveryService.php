<?php

namespace App\Services;

use App\Enums\AidDeliveryAction as Action;
use App\Enums\AidDeliveryState as State;
use App\Enums\AssistanceDeliveryMethod;
use App\Models\AidDelivery;
use App\Models\AidDeliveryProof;
use App\Models\AidDeliveryTransition;
use App\Models\AssistanceCoordination;
use App\Models\Campaign;
use App\Models\Category;
use App\Models\Donation;
use App\Models\HelpApplication;
use App\Models\User;
use App\Policies\AidDeliveryPolicy;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class AidDeliveryService
{
    public function __construct(private readonly AidDeliveryPolicy $policy, private readonly AuditLogger $audit, private readonly AidDeliveryNotifications $notifications) {}

    /**
     * All delivery mutations lock Application -> Users ascending -> Campaign ->
     * Category -> Coordination -> Deliveries/Transitions/Proofs -> Audit/Outbox.
     * Donation settlement uses the same Application/Campaign mutex; succeeded
     * donations are read under it, without another lock order or SQL float SUM.
     * Never select encrypted receiving details in authorization/readiness queries.
     */
    private function lock(User $actor, string $applicationReference, string $coordinationReference, bool $administrator): array
    {
        $application = HelpApplication::query()->select(AssistanceCoordinationService::APPLICATION_FIELDS)
            ->selectRaw('CASE WHEN decision_note IS NOT NULL AND decision_note <> ? THEN 1 ELSE 0 END AS has_decision_note', [''])
            ->where('reference', $applicationReference)->lockForUpdate()->firstOrFail();
        $users = User::query()->select(['id', 'role', 'is_active', 'must_change_password'])
            ->whereIn('id', array_filter([$actor->id, $application->applicant_id, $application->reviewed_by]))->orderBy('id')->lockForUpdate()->get();
        $actor = $users->firstWhere('id', $actor->id);
        abort_unless($actor && ($administrator ? $this->policy->administer($actor, $application) : $this->policy->applicant($actor, $application)), 404);
        $campaigns = Campaign::withTrashed()->where('help_application_id', $application->id)->orderBy('id')->lockForUpdate()->get();
        abort_unless($campaigns->count() === 1, 404);
        $campaign = $campaigns->first();
        $category = Category::withTrashed()->select(['id', 'is_active', 'deleted_at'])->whereKey($campaign->category_id)->lockForUpdate()->first();
        $coordination = AssistanceCoordination::query()->select(AssistanceCoordinationService::COORDINATION_FIELDS)
            ->selectRaw('CASE WHEN delivery_details IS NOT NULL THEN 1 ELSE 0 END AS has_delivery_details')
            ->where('reference', $coordinationReference)->lockForUpdate()->firstOrFail();
        abort_unless(app(AssistanceCoordinationService::class)->historicallyCoherent($application, $campaign, $coordination), 404);
        $deliveries = AidDelivery::where('coordination_id', $coordination->id)->orderBy('id')->lockForUpdate()->get();
        $transitions = AidDeliveryTransition::whereIn('delivery_id', $deliveries->modelKeys())->orderBy('id')->lockForUpdate()->get();
        $proofs = AidDeliveryProof::whereIn('delivery_id', $deliveries->modelKeys())->orderBy('id')->lockForUpdate()->get();

        return compact('application', 'actor', 'campaign', 'category', 'coordination', 'deliveries', 'transitions', 'proofs', 'users');
    }

    private function ready(array $context): bool
    {
        extract($context);
        try {
            $first = $deliveries->isEmpty();
            if ($campaign->trashed() || ! $category || $category->trashed() || ! $category->is_active
                || $application->open_slot !== true || $application->category_assigned_by === null || $application->decided_by === null
                || ! $application->has_decision_note || $application->appeal_eligibility_ended_at !== null
                || $users->firstWhere('id', $application->applicant_id)?->getRawOriginal('role') !== 'user'
                || $campaign->getRawOriginal('status') !== ($first ? 'funded' : 'aid_delivery')
                || $application->getRawOriginal('status') !== ($first ? 'campaign_active' : 'aid_delivery')
                || $campaign->completed_at !== null || $campaign->cancelled_at !== null
                || ! $coordination->has_delivery_details || AssistanceDeliveryMethod::tryFrom((string) $coordination->getRawOriginal('delivery_method')) === null) {
                return false;
            }
            $target = CampaignApplicationAmount::canonical((string) $campaign->getRawOriginal('target_amount'));
            $raised = CampaignApplicationAmount::canonical((string) $campaign->getRawOriginal('raised_amount'));
            $requested = CampaignApplicationAmount::canonical((string) $application->getRawOriginal('requested_amount'));
            if ($target === null || $raised === null || $requested === null || CampaignApplicationAmount::exceeds($target, $requested)
                || ! BigDecimal::of($target)->isEqualTo($raised)) {
                return false;
            }
            $times = [$application->submitted_at, $application->review_started_at, $application->category_assigned_at,
                $application->decided_at, $campaign->created_at, $campaign->published_at, $campaign->funded_at,
                $coordination->started_at, $coordination->confirmed_at];
            if (! $first) {
                $times[] = $campaign->aid_delivery_started_at;
            }
            foreach ($times as $index => $time) {
                if ($time === null || $time->isFuture() || ($index > 0 && $times[$index - 1]->gt($time))) {
                    return false;
                }
            }
            if ($first && $campaign->aid_delivery_started_at !== null) {
                return false;
            }
            if ($application->status_changed_at === null || ! $application->status_changed_at->equalTo($first ? $campaign->published_at : $campaign->aid_delivery_started_at)) {
                return false;
            }
            if (! $first && ! $deliveries->first()->started_at->equalTo($campaign->aid_delivery_started_at)) {
                return false;
            }
            $funding = $this->funding($campaign);
            if (! $funding->isEqualTo($campaign->raised_amount)) {
                return false;
            }
            $unfinished = 0;
            foreach ($deliveries as $delivery) {
                if (! DonationMoney::canonical((string) $delivery->getRawOriginal('amount')) || $delivery->currency !== 'SDG' || $delivery->revision < 1) {
                    return false;
                }
                $history = $transitions->where('delivery_id', $delivery->id);
                $last = $history->last();
                if ($history->count() !== $delivery->revision || ! $last || $last->revision !== $delivery->revision || $last->state !== $delivery->state) {
                    return false;
                }
                $proof = $proofs->firstWhere('delivery_id', $delivery->id);
                if ($delivery->state === State::SimulatedDelivered) {
                    if (! $proof || ! $delivery->completed_at || ! $delivery->completed_at->equalTo($last->created_at) || ! $proof->created_at->equalTo($last->created_at)) {
                        return false;
                    }
                } else {
                    $unfinished++;
                    if ($proof || $delivery->completed_at !== null) {
                        return false;
                    }
                }
            }

            return $unfinished <= 1 && $funding->isGreaterThanOrEqualTo($this->delivered($deliveries));
        } catch (Throwable) {
            return false;
        }
    }

    private function funding(Campaign $campaign): BigDecimal
    {
        $sum = BigDecimal::zero();
        foreach (Donation::query()->select(['amount', 'currency'])->where('campaign_id', $campaign->id)->where('status', 'succeeded')->get() as $donation) {
            $amount = (string) $donation->getRawOriginal('amount');
            abort_unless($donation->currency === 'SDG' && DonationMoney::canonical($amount), 404);
            $sum = $sum->plus($amount);
        }

        return $sum;
    }

    private function delivered($deliveries): BigDecimal
    {
        $sum = BigDecimal::zero();
        foreach ($deliveries as $delivery) {
            if ($delivery->state === State::SimulatedDelivered) {
                $sum = $sum->plus($delivery->amount);
            }
        }

        return $sum;
    }

    public function complete(User $actor, string $applicationReference, string $coordinationReference, int $expectedRevision): void
    {
        DB::transaction(function () use ($actor, $applicationReference, $coordinationReference, $expectedRevision): void {
            $context = $this->lock($actor, $applicationReference, $coordinationReference, true);
            abort_unless($this->ready($context), 404);
            extract($context);
            abort_unless($coordination->revision === $expectedRevision && $deliveries->isNotEmpty()
                && $deliveries->every(fn ($delivery) => $delivery->state === State::SimulatedDelivered
                    && $delivery->unfinished_coordination_id === null)
                && $proofs->count() === $deliveries->count(), 404);
            foreach ($deliveries as $delivery) {
                $history = $transitions->where('delivery_id', $delivery->id)->sortBy('revision')->values();
                $previous = null;
                foreach ($history as $index => $transition) {
                    abort_unless($transition->revision === $index + 1
                        && $transition->created_at !== null && ! $transition->created_at->isFuture()
                        && ($previous === null || $transition->created_at->gte($previous)), 404);
                    $previous = $transition->created_at;
                }
                abort_unless($history->isNotEmpty() && $history->first()->created_at->equalTo($delivery->started_at)
                    && $history->last()->created_at->equalTo($delivery->completed_at)
                    && $delivery->started_at->gte($coordination->confirmed_at), 404);
            }
            $funding = $this->funding($campaign);
            $target = CampaignApplicationAmount::canonical((string) $campaign->getRawOriginal('target_amount'));
            abort_unless($target !== null && $funding->isEqualTo($target)
                && $funding->isEqualTo($this->delivered($deliveries)), 404);
            $timestamp = CarbonImmutable::now(config('app.timezone'))->startOfSecond();
            abort_unless($deliveries->every(fn ($delivery) => $delivery->completed_at->lte($timestamp)), 404);
            $campaign->status = 'completed';
            $campaign->completed_at = $timestamp;
            $campaign->updated_by = $actor->id;
            $campaign->updated_at = $timestamp;
            $campaign->timestamps = false;
            $campaign->save();
            $application->status = 'completed';
            $application->open_slot = null;
            $application->status_changed_at = $timestamp;
            $application->updated_by = $actor->id;
            $application->updated_at = $timestamp;
            $application->timestamps = false;
            $application->save();
            $this->audit->log('campaign.completed', actor: $actor, subject: $campaign,
                oldValues: ['status' => 'aid_delivery'], newValues: ['status' => 'completed'], createdAt: $timestamp);
            $this->audit->log('help_application.completed', actor: $actor, subject: $application,
                oldValues: ['status' => 'aid_delivery', 'open_slot' => true],
                newValues: ['status' => 'completed', 'open_slot' => null], createdAt: $timestamp);
            app(AssistanceCompletionNotifications::class)->record($application, $users, $timestamp);
        });
    }

    public function completionRevision(User $actor, string $application, string $coordination): ?int
    {
        return DB::transaction(function () use ($actor, $application, $coordination): ?int {
            $context = $this->lock($actor, $application, $coordination, true);

            return $this->ready($context) && $context['deliveries']->isNotEmpty()
                && $context['deliveries']->every(fn ($delivery) => $delivery->state === State::SimulatedDelivered)
                && $this->funding($context['campaign'])->isEqualTo($this->delivered($context['deliveries']))
                ? $context['coordination']->revision : null;
        });
    }

    public function detail(User $actor, string $application, string $coordination, bool $administrator): array
    {
        return DB::transaction(function () use ($actor, $application, $coordination, $administrator) {
            $context = $this->lock($actor, $application, $coordination, $administrator);
            $context['administrator'] = $administrator;
            $context['canMutate'] = $administrator && $this->ready($context);
            $context['delivered'] = (string) $this->delivered($context['deliveries'])->toScale(2);
            $context['remaining'] = (string) $this->funding($context['campaign'])->minus($context['delivered'])->toScale(2);

            return $context;
        });
    }

    public function mutate(User $actor, string $application, string $coordination, string $action, array $input, ?string $deliveryReference = null): AidDelivery
    {
        abort_unless(AidDeliveryInput::valid($action, $input), 404);

        return DB::transaction(function () use ($actor, $application, $coordination, $action, $input, $deliveryReference) {
            $context = $this->lock($actor, $application, $coordination, true);
            abort_unless($this->ready($context), 404);
            extract($context);
            $timestamp = CarbonImmutable::now(config('app.timezone'))->startOfSecond();
            if ($action === 'start') {
                $existing = AidDelivery::where('entry_key', $input['entry_key'])->first();
                if ($existing) {
                    abort_unless($existing->coordination_id === $coordination->id && $existing->started_by === $actor->id
                        && BigDecimal::of($existing->amount)->isEqualTo($input['amount']), 404);

                    return $existing;
                }
                abort_unless($deliveries->every(fn ($item) => $item->state === State::SimulatedDelivered)
                    && BigDecimal::of($input['amount'])->isLessThanOrEqualTo($this->funding($campaign)->minus($this->delivered($deliveries))), 404);
                $delivery = new AidDelivery;
                $delivery->reference = (string) Str::uuid();
                $delivery->coordination_id = $coordination->id;
                $delivery->amount = $input['amount'];
                $delivery->currency = 'SDG';
                $delivery->entry_key = $input['entry_key'];
                $delivery->started_by = $actor->id;
                $delivery->started_at = $timestamp;
                $delivery->revision = 1;
                $delivery->state = State::InProgress;
                $delivery->save();
                if ($deliveries->isEmpty()) {
                    $campaign->status = 'aid_delivery';
                    $campaign->aid_delivery_started_at = $timestamp;
                    $campaign->timestamps = false;
                    $campaign->save();
                    $application->status = 'aid_delivery';
                    $application->status_changed_at = $timestamp;
                    $application->updated_by = $actor->id;
                    $application->timestamps = false;
                    $application->save();
                    $this->audit->log('campaign.aid_delivery_started', subject: $campaign, oldValues: ['status' => 'funded'], newValues: ['status' => 'aid_delivery'], createdAt: $timestamp);
                    $this->audit->log('help_application.aid_delivery_started', subject: $application, oldValues: ['status' => 'campaign_active'], newValues: ['status' => 'aid_delivery'], createdAt: $timestamp);
                }
                $old = null;
                $transitionAction = Action::Started;
            } else {
                $delivery = $deliveries->firstWhere('reference', $deliveryReference);
                abort_unless($delivery && (string) $delivery->revision === $input['revision'], 404);
                $old = $delivery->state;
                $transitionAction = match ($action) {
                    'problem' => Action::ProblemRecorded, 'resume' => Action::Resumed, 'success' => Action::SimulatedDelivered,
                };
                abort_unless($old === ($action === 'resume' ? State::Problem : State::InProgress), 404);
                abort_unless(BigDecimal::of($delivery->amount)->isLessThanOrEqualTo($this->funding($campaign)->minus($this->delivered($deliveries))), 404);
                $delivery->state = $transitionAction->state();
                $delivery->revision++;
                if ($action === 'success') {
                    $delivery->completed_at = $timestamp;
                }
                $delivery->save();
                if ($action === 'success') {
                    $proof = new AidDeliveryProof;
                    $proof->reference = (string) Str::uuid();
                    $proof->delivery_id = $delivery->id;
                    $proof->sandbox_reference = bin2hex(random_bytes(32));
                    $proof->generator = 'academic-sandbox';
                    $proof->version = 1;
                    $proof->created_at = $timestamp;
                    $proof->save();
                }
            }
            $transition = new AidDeliveryTransition;
            $transition->reference = (string) Str::uuid();
            $transition->delivery_id = $delivery->id;
            $transition->revision = $delivery->revision;
            $transition->state = $delivery->state;
            $transition->action = $transitionAction;
            $transition->actor_id = $actor->id;
            $transition->note = $input['note'] ?? null;
            $transition->created_at = $timestamp;
            $transition->save();
            $this->audit->log('aid_delivery.'.$transitionAction->value, subject: $delivery,
                oldValues: $old ? ['state' => $old->value] : null, newValues: ['state' => $delivery->state->value], createdAt: $timestamp);
            $this->notifications->record($delivery, $application, $transition, $users);

            return $delivery;
        });
    }
}
