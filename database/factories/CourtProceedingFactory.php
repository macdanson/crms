<?php

namespace Database\Factories;

use App\Enums\CourtProceedingStatus;
use App\Models\CourtProceeding;
use App\Models\CriminalCase;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CourtProceeding>
 */
class CourtProceedingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'case_id' => CriminalCase::factory(),
            'court_name' => 'Synthetic Test Court',
            'court_reference' => 'TEST-COURT-'.fake()->unique()->numerify('####'),
            'status' => CourtProceedingStatus::Filed,
            'recorded_by' => User::factory(),
        ];
    }
}
