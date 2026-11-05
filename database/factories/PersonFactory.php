<?php

namespace Database\Factories;

use App\Models\Person;
use App\Models\Station;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Generates synthetic people only, with names that cannot be mistaken for real
 * records (developer guide §24).
 *
 * @extends Factory<Person>
 */
class PersonFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'given_names' => 'Synthetic'.fake()->unique()->numerify('######'),
            'family_name' => 'Testperson',
            'sex' => null,
            'date_of_birth_ciphertext' => '1990-01-15',
            'birth_year' => 1990,
            'registered_station_id' => Station::factory(),
            'registered_district_id' => fn (array $attributes): int => Station::query()->findOrFail((int) $attributes['registered_station_id'])->district_id,
            'created_by' => User::factory(),
        ];
    }

    public function registeredAt(Station $station): static
    {
        return $this->state(fn (array $attributes) => [
            'registered_station_id' => $station->id,
            'registered_district_id' => $station->district_id,
        ]);
    }
}
