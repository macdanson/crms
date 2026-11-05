<?php

namespace Database\Factories;

use App\Enums\CourtOutcomeType;
use App\Enums\OutcomeVerification;
use App\Models\CasePerson;
use App\Models\CourtOutcome;
use App\Models\CourtProceeding;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CourtOutcome>
 */
class CourtOutcomeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'court_proceeding_id' => CourtProceeding::factory(),
            'case_person_id' => CasePerson::factory(),
            'outcome' => CourtOutcomeType::Pending,
            'outcome_date' => now()->subDay()->toDateString(),
            'source' => 'Synthetic court record',
            'verification_status' => OutcomeVerification::Pending,
            'recorded_by' => User::factory(),
            'recorded_at' => now(),
        ];
    }
}
