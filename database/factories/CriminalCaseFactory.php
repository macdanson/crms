<?php

namespace Database\Factories;

use App\Enums\CaseStatus;
use App\Enums\RecordLifecycle;
use App\Models\CaseAssignment;
use App\Models\CriminalCase;
use App\Models\OffenceCategory;
use App\Models\Station;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Generates synthetic cases only (developer guide §24).
 *
 * @extends Factory<CriminalCase>
 */
class CriminalCaseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reference' => 'TEST/'.fake()->unique()->numerify('########'),
            'station_id' => Station::factory(),
            'district_id' => fn (array $attributes): int => Station::query()->findOrFail((int) $attributes['station_id'])->district_id,
            'offence_category_id' => OffenceCategory::factory(),
            'incident_date' => now()->subDays(3)->toDateString(),
            'incident_location' => 'Synthetic location',
            'narrative_ciphertext' => 'Synthetic narrative for testing.',
            'status' => CaseStatus::Open,
            'lifecycle' => RecordLifecycle::Active,
            'created_by' => User::factory(),
        ];
    }

    public function atStation(Station $station): static
    {
        return $this->state(fn (array $attributes) => [
            'station_id' => $station->id,
            'district_id' => $station->district_id,
        ]);
    }

    public function assignedTo(User $officer): static
    {
        return $this->afterCreating(function (CriminalCase $case) use ($officer): void {
            CaseAssignment::factory()->create(['case_id' => $case->id, 'user_id' => $officer->id]);
        });
    }

    public function restricted(): static
    {
        return $this->state(fn (array $attributes) => ['lifecycle' => RecordLifecycle::Restricted]);
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes) => ['lifecycle' => RecordLifecycle::Archived]);
    }
}
