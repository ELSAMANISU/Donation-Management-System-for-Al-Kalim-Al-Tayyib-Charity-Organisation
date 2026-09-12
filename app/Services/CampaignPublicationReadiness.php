<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\Category;
use App\Models\HelpApplication;
use Brick\Math\Exception\MathException;
use Carbon\Exceptions\InvalidFormatException;
use Throwable;

class CampaignPublicationReadiness
{
    public function missing(Campaign $campaign, ?Category $category): array
    {
        $missing = [];
        foreach (['title' => 255, 'summary' => 1000, 'story' => 20000, 'image_alt' => 255] as $prefix => $limit) {
            foreach (['ar', 'en'] as $locale) {
                $value = $campaign->{$prefix.'_'.$locale};
                if (! is_string($value) || trim($value) === '' || ! mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > $limit) {
                    $missing[] = $prefix.'_'.$locale;
                }
            }
        }
        if (! is_string($campaign->slug) || strlen($campaign->slug) > 160 || preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $campaign->slug) !== 1) {
            $missing[] = 'slug';
        }
        if (CampaignApplicationAmount::canonical((string) $campaign->getRawOriginal('target_amount')) === null) {
            $missing[] = 'target_amount';
        }
        if (! $category || ! $category->is_active || $category->trashed()) {
            $missing[] = 'category';
        }
        $invalid = $campaign->trashed() || $campaign->getRawOriginal('status') !== 'draft' || preg_match('/\A0(?:\.0{1,2})?\z/', (string) $campaign->getRawOriginal('raised_amount')) !== 1;
        foreach (['published_at', 'expires_at', 'paused_at', 'pause_reason', 'funded_at', 'aid_delivery_started_at', 'completed_at', 'cancelled_at', 'cancellation_reason'] as $field) {
            $invalid = $invalid || $campaign->getRawOriginal($field) !== null;
        }
        if ($invalid) {
            $missing[] = 'lifecycle';
        }
        try {
            app(CampaignImageService::class)->preview($campaign);
        } catch (Throwable) {
            $missing[] = 'image';
        }

        return $missing;
    }

    public function linkedReady(HelpApplication $application, Campaign $campaign): bool
    {
        try {
            return $this->inspectApplication($application, $campaign);
        } catch (InvalidFormatException|\ValueError|MathException) {
            return false;
        }
    }

    private function inspectApplication(HelpApplication $application, Campaign $campaign): bool
    {
        if (! is_string($application->reference) || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $application->reference) !== 1
            || $application->applicant_id === null || $application->getRawOriginal('status') !== 'converted_to_campaign' || $application->open_slot !== true
            || $application->category_id !== $campaign->category_id
            || $application->decided_by === null || $application->category_assigned_by === null
            || ! $application->has_decision_note || $application->appeal_eligibility_ended_at !== null) {
            return false;
        }
        foreach (['submitted_at', 'review_started_at', 'category_assigned_at', 'decided_at', 'status_changed_at'] as $field) {
            if ($application->{$field} === null || $application->{$field}->isFuture()) {
                return false;
            }
        }
        $maximum = CampaignApplicationAmount::canonical($application->requested_amount);

        return $maximum !== null && ! CampaignApplicationAmount::exceeds($campaign->target_amount, $maximum)
            && $application->submitted_at->lte($application->review_started_at)
            && $application->review_started_at->lte($application->category_assigned_at)
            && $application->category_assigned_at->lte($application->decided_at)
            && $application->decided_at->lte($application->status_changed_at)
            && $application->status_changed_at->equalTo($campaign->created_at);
    }
}
