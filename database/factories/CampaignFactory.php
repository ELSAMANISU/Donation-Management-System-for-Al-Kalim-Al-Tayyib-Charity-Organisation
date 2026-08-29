<?php

namespace Database\Factories;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Campaign> */
class CampaignFactory extends Factory
{
    protected $model = Campaign::class;

    public function definition(): array
    {
        $title = fake()->unique()->sentence(4);

        return [
            'category_id' => Category::factory(),
            'slug' => Str::slug($title).'-'.fake()->unique()->numberBetween(1, 1000000),
            'title_ar' => 'حملة خيرية تجريبية',
            'title_en' => $title,
            'summary_ar' => 'ملخص عام وآمن للحملة الخيرية.',
            'summary_en' => 'A privacy-safe public campaign summary.',
            'story_ar' => 'قصة عامة وآمنة توضح هدف الحملة دون معلومات خاصة.',
            'story_en' => 'A privacy-safe public story explaining the campaign goal.',
            'target_amount' => fake()->randomElement(['10000.00', '25000.00', '50000.00']),
            'raised_amount' => '0.00',
            'status' => CampaignStatus::Draft,
            'is_featured' => false,
            'is_urgent' => false,
            'priority' => 0,
            'image_path' => null,
            'image_alt_ar' => null,
            'image_alt_en' => null,
            'expires_at' => null,
            'published_at' => null,
            'paused_at' => null,
            'pause_reason' => null,
            'funded_at' => null,
            'aid_delivery_started_at' => null,
            'completed_at' => null,
            'cancelled_at' => null,
            'cancellation_reason' => null,
            'impact_update_ar' => null,
            'impact_update_en' => null,
            'created_by' => null,
            'updated_by' => null,
            'deleted_at' => null,
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => [
            'status' => CampaignStatus::Active,
            'published_at' => now()->subDay(),
        ]);
    }

    public function paused(): static
    {
        return $this->active()->state(fn () => [
            'status' => CampaignStatus::Paused,
            'paused_at' => now(),
            'pause_reason' => 'Campaign review in progress.',
        ]);
    }

    public function funded(): static
    {
        return $this->active()->state(fn () => [
            'status' => CampaignStatus::Funded,
            'target_amount' => '25000.00',
            'raised_amount' => '25000.00',
            'funded_at' => now(),
        ]);
    }

    public function aidDelivery(): static
    {
        return $this->funded()->state(fn () => [
            'status' => CampaignStatus::AidDelivery,
            'aid_delivery_started_at' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->aidDelivery()->state(fn () => [
            'status' => CampaignStatus::Completed,
            'completed_at' => now(),
            'impact_update_ar' => 'تم تقديم المساعدة ونشر أثر الحملة.',
            'impact_update_en' => 'Aid was delivered and the campaign impact was published.',
        ]);
    }

    public function cancelled(): static
    {
        return $this->active()->state(fn () => [
            'status' => CampaignStatus::Cancelled,
            'cancelled_at' => now(),
            'cancellation_reason' => 'Campaign cancelled for a documented reason.',
        ]);
    }

    public function featured(): static
    {
        return $this->state(fn () => ['is_featured' => true]);
    }

    public function urgent(): static
    {
        return $this->state(fn () => ['is_urgent' => true]);
    }

    public function priority(int $priority): static
    {
        return $this->state(fn () => ['priority' => $priority]);
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subDay()]);
    }

    public function trashed(): static
    {
        return $this->state(fn () => ['deleted_at' => now()]);
    }
}
