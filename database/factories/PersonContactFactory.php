<?php

namespace Database\Factories;

use App\Enums\ContactType;
use App\Models\Person;
use App\Models\PersonContact;
use App\Models\User;
use App\Services\DataProtection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PersonContact>
 */
class PersonContactFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $value = '+000 700 '.fake()->numerify('### ###');

        return [
            'person_id' => Person::factory(),
            'type' => ContactType::Phone,
            'value_ciphertext' => $value,
            'value_masked' => app(DataProtection::class)->maskContact($value),
            'created_by' => User::factory(),
        ];
    }
}
