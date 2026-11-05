<?php

namespace Database\Factories;

use App\Models\District;
use App\Models\Station;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Generates synthetic stations only (developer guide §24).
 *
 * @extends Factory<Station>
 */
class StationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'district_id' => District::factory(),
            'name' => 'Test Station '.fake()->unique()->numerify('####'),
            'code' => 'S'.fake()->unique()->numerify('####'),
            'is_active' => true,
        ];
    }
}
