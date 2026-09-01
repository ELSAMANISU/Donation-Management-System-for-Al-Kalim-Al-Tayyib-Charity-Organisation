<?php

namespace Database\Factories;

use App\Enums\HelpApplicationDuplicateWarningStatus;
use App\Models\HelpApplication;
use App\Models\HelpApplicationDuplicateWarning;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<HelpApplicationDuplicateWarning> */
class HelpApplicationDuplicateWarningFactory extends Factory
{
    protected $model = HelpApplicationDuplicateWarning::class;

    public function definition(): array
    {
        return [
            'reference' => (string) Str::uuid(),
            'matched_application_id' => HelpApplication::factory()->closed(),
            'submitted_application_id' => HelpApplication::factory()->pending(),
            'status' => HelpApplicationDuplicateWarningStatus::Unreviewed,
            'resolved_by' => null,
            'resolved_at' => null,
            'resolution_note' => null,
        ];
    }

    public function confirmed(): static
    {
        return $this->resolved(HelpApplicationDuplicateWarningStatus::ConfirmedMatch);
    }

    public function dismissed(): static
    {
        return $this->resolved(HelpApplicationDuplicateWarningStatus::Dismissed);
    }

    private function resolved(HelpApplicationDuplicateWarningStatus $status): static
    {
        return $this->state(fn () => [
            'status' => $status,
            'resolved_by' => User::factory()->admin(),
            'resolved_at' => now(),
            'resolution_note' => 'Private synthetic resolution note.',
        ]);
    }
}
