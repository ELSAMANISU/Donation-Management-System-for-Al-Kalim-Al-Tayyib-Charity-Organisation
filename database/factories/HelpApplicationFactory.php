<?php

namespace Database\Factories;

use App\Enums\HelpApplicationStatus;
use App\Enums\IdentityDocumentType;
use App\Enums\PublicIdentityPreference;
use App\Models\Category;
use App\Models\HelpApplication;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<HelpApplication> */
class HelpApplicationFactory extends Factory
{
    protected $model = HelpApplication::class;

    public function definition(): array
    {
        return [
            'reference' => (string) Str::uuid(),
            'applicant_id' => User::factory()->user(),
            'category_id' => null,
            'status' => HelpApplicationStatus::Draft,
            'open_slot' => true,
            'full_name' => fake()->name(),
            'email' => fake()->safeEmail(),
            'phone' => '+249 000 000 000',
            'address' => 'Private synthetic applicant address.',
            'date_of_birth' => '1990-01-01',
            'identity_document_type' => IdentityDocumentType::NationalId,
            'identity_issuing_country' => 'SD',
            'identity_document_number' => null,
            'identity_blind_index' => null,
            'identity_blind_index_version' => null,
            'requested_amount' => '1000.50',
            'private_story' => 'Private synthetic help application story.',
            'preferred_receiving_method' => 'A general private receiving preference.',
            'public_identity_preference' => PublicIdentityPreference::Anonymous,
            'consent_version' => null,
            'consented_at' => null,
            'category_assigned_by' => null,
            'category_assigned_at' => null,
            'reviewed_by' => null,
            'review_started_at' => null,
            'decided_by' => null,
            'decided_at' => null,
            'submitted_at' => null,
            'status_changed_at' => null,
            'appeal_eligibility_ended_at' => null,
            'updated_by' => null,
        ];
    }

    public function pending(): static
    {
        return $this->withStatus(HelpApplicationStatus::Pending, ['submitted_at' => now()]);
    }

    public function underReview(): static
    {
        return $this->withStatus(HelpApplicationStatus::UnderReview, [
            'submitted_at' => now()->subDay(),
            'review_started_at' => now(),
        ]);
    }

    public function additionalInformationRequired(): static
    {
        return $this->withStatus(HelpApplicationStatus::AdditionalInformationRequired, [
            'submitted_at' => now()->subDays(2),
            'review_started_at' => now()->subDay(),
        ]);
    }

    public function approved(): static
    {
        return $this->decided(HelpApplicationStatus::Approved);
    }

    public function rejected(): static
    {
        return $this->decided(HelpApplicationStatus::Rejected);
    }

    public function appealed(): static
    {
        return $this->decided(HelpApplicationStatus::Appealed);
    }

    public function convertedToCampaign(): static
    {
        return $this->decided(HelpApplicationStatus::ConvertedToCampaign);
    }

    public function campaignActive(): static
    {
        return $this->decided(HelpApplicationStatus::CampaignActive);
    }

    public function aidDelivery(): static
    {
        return $this->decided(HelpApplicationStatus::AidDelivery);
    }

    public function completed(): static
    {
        return $this->decided(HelpApplicationStatus::Completed);
    }

    public function closed(): static
    {
        return $this->decided(HelpApplicationStatus::Closed, [
            'appeal_eligibility_ended_at' => now(),
        ]);
    }

    public function assignedTo(Category $category, User $administrator): static
    {
        return $this->state(fn () => [
            'category_id' => $category->getKey(),
            'category_assigned_by' => $administrator->getKey(),
            'category_assigned_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function decided(HelpApplicationStatus $status, array $attributes = []): static
    {
        return $this->withStatus($status, array_merge([
            'submitted_at' => now()->subDays(3),
            'review_started_at' => now()->subDays(2),
            'decided_at' => now()->subDay(),
        ], $attributes));
    }

    /** @param array<string, mixed> $attributes */
    private function withStatus(HelpApplicationStatus $status, array $attributes = []): static
    {
        return $this->state(fn () => array_merge([
            'status' => $status,
            'open_slot' => $status->isOpen() ? true : null,
            'status_changed_at' => now(),
        ], $attributes));
    }
}
