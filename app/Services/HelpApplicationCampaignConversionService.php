<?php

namespace App\Services;

use App\Data\ConvertedHelpApplication;
use App\Enums\CampaignStatus;
use App\Enums\HelpApplicationStatus;
use App\Enums\UserRole;
use App\Http\Requests\Admin\StoreCampaignRequest;
use App\Models\Campaign;
use App\Models\Category;
use App\Models\HelpApplication;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class HelpApplicationCampaignConversionService
{
    public const FIELDS = ['id', 'reference', 'status', 'open_slot', 'reviewed_by', 'submitted_at', 'review_started_at', 'decided_by', 'decided_at', 'decision_note', 'status_changed_at', 'appeal_eligibility_ended_at', 'category_id', 'category_assigned_by', 'category_assigned_at', 'requested_amount'];

    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function query(User $actor, string $reference): Builder
    {
        return HelpApplication::query()->select(self::FIELDS)->where('reference', $reference)
            ->where('status', HelpApplicationStatus::Approved)
            ->when(! $actor->hasRole(UserRole::SuperAdmin), fn ($query) => $query->where('reviewed_by', $actor->getKey()));
    }

    public function eligible(HelpApplication $application): bool
    {
        if ($application->status !== HelpApplicationStatus::Approved || $application->open_slot !== true
            || CampaignApplicationAmount::canonical($application->requested_amount) === null
            || $application->submitted_at === null || $application->review_started_at === null
            || $application->decided_by === null || $application->decided_at === null
            || $application->getRawOriginal('decision_note') === null || $application->status_changed_at === null
            || ! $application->status_changed_at->equalTo($application->decided_at)
            || $application->submitted_at->gt($application->review_started_at)
            || $application->review_started_at->gt($application->decided_at)
            || $application->appeal_eligibility_ended_at !== null || $application->category_id === null
            || $application->category_assigned_by === null || $application->category_assigned_at === null
            || $application->category_assigned_at->lt($application->review_started_at)
            || $application->category_assigned_at->gt($application->decided_at)) {
            return false;
        }
        try {
            return is_string($application->decision_note) && trim($application->decision_note) !== '';
        } catch (DecryptException) {
            return false;
        }
    }

    public function convert(User $actor, string $reference, array $attributes): ConvertedHelpApplication
    {
        try {
            return DB::transaction(function () use ($actor, $reference, $attributes): ConvertedHelpApplication {
                $application = $this->query($actor, $reference)->lockForUpdate()->firstOrFail();
                $lockedActor = User::query()->select(['id', 'name', 'role', 'is_active', 'must_change_password'])
                    ->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
                abort_unless(Gate::forUser($lockedActor)->allows('convertToCampaign', $application) && $this->eligible($application), 404);
                $category = Category::withTrashed()->select('id')->whereKey($application->category_id)->lockForUpdate()->firstOrFail();
                abort_if(Campaign::withTrashed()->where('help_application_id', $application->id)->lockForUpdate()->first(['id']) !== null, 404);

                $rules = (new StoreCampaignRequest)->rules();
                unset($rules['category_id']);
                $attributes = Validator::make($attributes, $rules, (new StoreCampaignRequest)->messages())->validate();
                $amount = CampaignApplicationAmount::validate($attributes['target_amount'], $application->requested_amount);
                $timestamp = now()->toImmutable();
                $campaign = new Campaign;
                $campaign->help_application_id = $application->id;
                $campaign->category_id = $category->id;
                foreach (['slug', 'title_ar', 'title_en', 'summary_ar', 'summary_en', 'story_ar', 'story_en'] as $field) {
                    $campaign->{$field} = $attributes[$field];
                }
                $campaign->target_amount = $amount;
                $campaign->raised_amount = '0.00';
                $campaign->status = CampaignStatus::Draft;
                $campaign->is_featured = false;
                $campaign->is_urgent = false;
                $campaign->priority = 0;
                foreach (['image_path', 'image_alt_ar', 'image_alt_en', 'expires_at', 'published_at', 'paused_at', 'pause_reason', 'funded_at', 'aid_delivery_started_at', 'completed_at', 'cancelled_at', 'cancellation_reason', 'impact_update_ar', 'impact_update_en', 'deleted_at'] as $field) {
                    $campaign->{$field} = null;
                }
                $campaign->created_by = $campaign->updated_by = $lockedActor->id;
                $campaign->created_at = $campaign->updated_at = $timestamp;
                $campaign->timestamps = false;
                $campaign->save();
                $application->status = HelpApplicationStatus::ConvertedToCampaign;
                $application->status_changed_at = $application->updated_at = $timestamp;
                $application->updated_by = $lockedActor->id;
                $application->timestamps = false;
                $application->save();
                $this->auditLogger->log('campaign.created_from_help_application', actor: $lockedActor, subject: $campaign,
                    newValues: ['category_id' => $campaign->category_id, 'slug' => $campaign->slug, 'status' => 'draft', 'target_amount' => $campaign->target_amount, 'raised_amount' => '0.00', 'is_featured' => false, 'is_urgent' => false, 'priority' => 0]);
                $this->auditLogger->log('help_application.converted_to_campaign', actor: $lockedActor, subject: $application,
                    oldValues: ['status' => 'approved'], newValues: ['status' => 'converted_to_campaign']);

                return new ConvertedHelpApplication($campaign);
            });
        } catch (QueryException $exception) {
            if ($this->uniqueViolation($exception, 'help_application_id')) {
                abort(404);
            }
            if ($this->uniqueViolation($exception, 'slug')) {
                throw ValidationException::withMessages(['slug' => (new StoreCampaignRequest)->messages()['slug.unique']]);
            }
            throw $exception;
        }
    }

    private function uniqueViolation(QueryException $exception, string $column): bool
    {
        $state = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        $code = (int) ($exception->errorInfo[1] ?? 0);
        $diagnostic = (string) ($exception->errorInfo[2] ?? '');
        if ($state === '23000' && $code === 1062) {
            return preg_match('/\bfor key\s+[\'"`](?:[^\'"`]+\.)?campaigns_'.$column.'_unique[\'"`]/i', $diagnostic) === 1;
        }

        return in_array($state, ['23000', '23505'], true) && $code === 19
            && preg_match('/\bunique constraint failed:\s*[`"\[]?campaigns[`"\]]?\.[`"\[]?'.$column.'[`"\]]?(?:\s|$)/i', $diagnostic) === 1;
    }
}
