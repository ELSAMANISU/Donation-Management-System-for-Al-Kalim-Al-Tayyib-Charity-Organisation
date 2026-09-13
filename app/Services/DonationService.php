<?php

namespace App\Services;

use App\Contracts\DonationGateway;
use App\Enums\CampaignStatus;
use App\Enums\DonationStatus;
use App\Enums\HelpApplicationStatus;
use App\Enums\InternalNotificationAudience;
use App\Enums\InternalNotificationEventType;
use App\Enums\InternalNotificationProjectionState;
use App\Enums\InternalNotificationType;
use App\Enums\UserRole;
use App\Models\Campaign;
use App\Models\Category;
use App\Models\Donation;
use App\Models\HelpApplication;
use App\Models\InternalNotificationEvent;
use App\Models\InternalNotificationEventRecipient;
use App\Models\PaymentAttempt;
use App\Models\User;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class DonationService
{
    public function __construct(private readonly AuditLogger $audit, private readonly InternalNotificationEventKey $eventKey, private readonly InternalNotificationProjector $projector, private readonly DonationGateway $gateway) {}

    public function authorize(Request $request, Donation $donation): void
    {
        if ($donation->donor_id !== null) {
            abort_unless($request->user() && $request->user()->id === $donation->donor_id, 404);

            return;
        }
        $token = $request->route('capability');
        abort_unless(is_string($token) && preg_match('/\A[0-9a-f]{64}\z/', $token) === 1
            && is_string($donation->capability_hash) && hash_equals($donation->capability_hash, hash('sha256', $token)), 404);
    }

    public function begin(Request $request, Campaign $campaign, string $amount, bool $anonymous): array
    {
        abort_unless(config('donations.driver') === 'sandbox' && $this->gateway->provider() === 'sandbox', 404);
        abort_unless(DonationMoney::canonical($amount), 422);

        return DB::transaction(function () use ($request, $campaign, $amount, $anonymous): array {
            [$locked, $actor] = $this->lockCampaign($campaign, $request);
            $entryKey = app(DonationFormTokens::class)->entryKey($request, $locked);
            $token = $actor === null ? hash_hmac('sha256', $entryKey, $request->session()->token()) : null;
            $existing = Donation::query()->where('entry_key', $entryKey)->lockForUpdate()->first();
            if ($existing) {
                abort_unless($existing->donor_id === $actor?->id && ($token === null || hash_equals($existing->capability_hash, hash('sha256', $token))), 404);

                return [$existing, $token];
            }
            abort_unless($this->available($locked), 404);
            if (BigDecimal::of($amount)->isGreaterThan(DonationMoney::remaining($locked))) {
                throw ValidationException::withMessages(['amount' => 'Amount exceeds the remaining target. / المبلغ يتجاوز المتبقي.']);
            }
            $at = CarbonImmutable::now(config('app.timezone'))->startOfSecond();

            $donation = new Donation;
            $donation->reference = (string) Str::uuid();
            $donation->entry_key = $entryKey;
            $donation->campaign_id = $locked->id;
            $donation->donor_id = $actor?->id;
            $donation->amount = (string) BigDecimal::of($amount)->toScale(2);
            $donation->currency = 'SDG';
            $donation->anonymous = $actor !== null && $anonymous;
            $donation->capability_hash = $token === null ? null : hash('sha256', $token);
            $donation->status = DonationStatus::Pending;
            $donation->expires_at = $at->addMinutes(config('donations.checkout_minutes'));
            $donation->save();
            $attempt = new PaymentAttempt;
            $attempt->reference = (string) Str::uuid();
            $attempt->donation_id = $donation->id;
            $attempt->provider = $this->gateway->provider();
            $attempt->provider_reference = $this->gateway->reference();
            $attempt->status = DonationStatus::Pending;
            $attempt->save();

            return [$donation, $token];
        });
    }

    public function settle(Request $request, Donation $donation, string $action): Donation
    {
        abort_unless(config('donations.driver') === 'sandbox', 404);
        $outcome = $this->gateway->outcome($action);
        $this->authorize($request, $donation);
        $snapshot = Campaign::withTrashed()->whereKey($donation->campaign_id)->firstOrFail();

        return DB::transaction(function () use ($request, $donation, $snapshot, $outcome): Donation {
            [$campaign, $actor, $application] = $this->lockCampaign($snapshot, $request);
            $locked = Donation::query()->whereKey($donation->id)->lockForUpdate()->firstOrFail();
            $attempt = PaymentAttempt::query()->where('donation_id', $locked->id)->lockForUpdate()->firstOrFail();
            $this->authorize($request, $locked);
            abort_unless($locked->campaign_id === $campaign->id && $locked->reference === $donation->reference && $attempt->provider === $this->gateway->provider(), 404);
            if ($locked->status !== DonationStatus::Pending) {
                return $locked;
            }
            abort_unless($attempt->status === DonationStatus::Pending, 404);
            $at = CarbonImmutable::now(config('app.timezone'))->startOfSecond();
            $final = $outcome;
            if ($locked->expires_at->lte($at)) {
                $final = DonationStatus::Expired;
            }
            if ($final === DonationStatus::Succeeded && (! $this->available($campaign)
                || ! DonationMoney::canonical($locked->amount) || $locked->currency !== 'SDG'
                || BigDecimal::of($locked->amount)->isGreaterThan(DonationMoney::remaining($campaign)))) {
                $final = DonationStatus::Expired;
            }
            if ($final === DonationStatus::Succeeded && $application) {
                abort_unless($application->status === HelpApplicationStatus::CampaignActive, 404);
            }
            $this->finalize($locked, $attempt, $final, $at);
            if ($final === DonationStatus::Succeeded) {
                $campaign->raised_amount = (string) BigDecimal::of($campaign->raised_amount)->plus($locked->amount)->toScale(2);
                $funded = BigDecimal::of($campaign->raised_amount)->isEqualTo($campaign->target_amount);
                if ($funded) {
                    $campaign->status = CampaignStatus::Funded;
                    $campaign->funded_at = $at;
                }
                $campaign->updated_at = $at;
                $campaign->timestamps = false;
                $campaign->save();
                if ($funded) {
                    $this->audit->log('campaign.funded', subject: $campaign, oldValues: ['status' => 'active'], newValues: ['status' => 'funded']);
                    if ($application) {
                        $this->notifyFunding($application, $at);
                    }
                }
            }

            return $locked;
        });
    }

    public function expireDue(int $limit = 100): int
    {
        abort_unless(config('donations.driver') === 'sandbox', 404);
        if ($limit < 1 || $limit > 1000) {
            throw new \InvalidArgumentException('Invalid expiry batch size.');
        }
        $ids = Donation::query()->where('status', DonationStatus::Pending)->where('expires_at', '<=', now())->orderBy('id')->limit($limit)->pluck('id');
        $expired = 0;
        foreach ($ids as $id) {
            $snapshot = Donation::query()->select(['id', 'campaign_id'])->findOrFail($id);
            $campaign = Campaign::withTrashed()->whereKey($snapshot->campaign_id)->firstOrFail();
            $expired += DB::transaction(function () use ($id, $campaign): int {
                // Trusted maintenance entry: no donor capability required, and no accounting effect.
                $this->lockCampaign($campaign, Request::create('/'));
                $donation = Donation::query()->whereKey($id)->lockForUpdate()->firstOrFail();
                $attempt = PaymentAttempt::query()->where('donation_id', $id)->lockForUpdate()->firstOrFail();
                $at = CarbonImmutable::now(config('app.timezone'))->startOfSecond();
                if ($donation->status !== DonationStatus::Pending || $donation->expires_at->gt($at)) {
                    return 0;
                }
                abort_unless($attempt->status === DonationStatus::Pending && $donation->campaign_id === $campaign->id, 404);
                $this->finalize($donation, $attempt, DonationStatus::Expired, $at);

                return 1;
            });
        }

        return $expired;
    }

    private function finalize(Donation $locked, PaymentAttempt $attempt, DonationStatus $final, CarbonImmutable $at): void
    {
        $attempt->status = $locked->status = $final;
        $attempt->completed_at = $locked->completed_at = $at;
        $attempt->updated_at = $locked->updated_at = $at;
        $attempt->timestamps = $locked->timestamps = false;
        if ($final === DonationStatus::Succeeded) {
            $locked->paid_at = $at;
        }
        $attempt->save();
        $locked->save();
        // Actor intentionally omitted: audit JSON and actor snapshots contain no donor identity.
        $this->audit->log('donation.finalized', subject: $locked, oldValues: ['status' => 'pending'], newValues: ['status' => $final->value]);
        $this->audit->log('payment_attempt.finalized', subject: $attempt, oldValues: ['status' => 'pending'], newValues: ['status' => $final->value]);
    }

    private function available(Campaign $campaign): bool
    {
        return ! $campaign->trashed() && DonationMoney::eligible($campaign)
            && $campaign->published_at !== null && $campaign->published_at->lte(now())
            && ($campaign->expires_at === null || $campaign->expires_at->gt(now()))
            && Category::query()->whereKey($campaign->category_id)->active()->exists();
    }

    private function lockCampaign(Campaign $snapshot, Request $request): array
    {
        // Compatible with publication/update/conversion: Application -> Actor -> Campaign -> Category
        // -> Donation -> PaymentAttempt. All linked operations serialize on Application first.
        $application = $snapshot->help_application_id === null ? null : HelpApplication::query()
            ->select(['id', 'reference', 'applicant_id', 'status'])->whereKey($snapshot->help_application_id)->lockForUpdate()->firstOrFail();
        $actor = $request->user() === null ? null : User::query()->select(['id', 'is_active', 'must_change_password'])->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
        abort_if($actor && (! $actor->is_active || $actor->must_change_password), 404);
        $campaign = Campaign::withTrashed()->whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
        abort_unless($campaign->help_application_id === $snapshot->help_application_id && $campaign->slug === $snapshot->slug, 404);
        Category::withTrashed()->select(['id'])->whereKey($campaign->category_id)->lockForUpdate()->firstOrFail();

        return [$campaign, $actor, $application];
    }

    private function notifyFunding(HelpApplication $application, CarbonImmutable $at): void
    {
        $event = new InternalNotificationEvent;
        $event->reference = (string) Str::uuid();
        $event->type = InternalNotificationEventType::CampaignFundingCompleted;
        $event->help_application_id = $application->id;
        $event->deduplication_key = $this->eventKey->make($event->type, $application->id);
        $event->occurred_at = $at;
        $event->save();
        $intent = new InternalNotificationEventRecipient;
        $intent->event_id = $event->id;
        $intent->recipient_id = $application->applicant_id;
        $intent->recipient_role = UserRole::User;
        $intent->audience = InternalNotificationAudience::Applicant;
        $intent->notification_type = InternalNotificationType::CampaignFundingCompleted;
        $intent->state = InternalNotificationProjectionState::Pending;
        $intent->attempts = 0;
        $intent->available_at = $at;
        $intent->save();
        DB::afterCommit(function () use ($event): void {
            try {
                $this->projector->projectEvent($event->id);
            } catch (Throwable) {
                try {
                    Log::warning('Campaign funding notification projection could not be completed.');
                } catch (Throwable) {
                }
            }
        });
    }
}
