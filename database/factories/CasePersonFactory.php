<?php

namespace Database\Factories;

use App\Enums\CasePersonRole;
use App\Models\CasePerson;
use App\Models\CriminalCase;
use App\Models\Person;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CasePerson>
 */
class CasePersonFactory extends Factory
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
            'person_id' => Person::factory(),
            'role' => CasePersonRole::Witness,
            'linked_by' => User::factory(),
            'linked_at' => now(),
        ];
    }
}
