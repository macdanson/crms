<?php

namespace Database\Factories;

use App\Models\DuplicateCandidate;
use App\Models\Person;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DuplicateCandidate>
 */
class DuplicateCandidateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'first_person_id' => Person::factory(),
            'second_person_id' => Person::factory(),
            'reason' => DuplicateCandidate::REASON_NAME_AND_YEAR,
            'confidence' => 70,
        ];
    }
}
