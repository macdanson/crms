<?php

namespace Database\Factories;

use App\Models\District;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Generates synthetic districts only (developer guide §24).
 *
 * @extends Factory<District>
 */
class DistrictFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Test District '.fake()->unique()->numerify('####'),
            'code' => 'D'.fake()->unique()->numerify('####'),
            'is_active' => true,
        ];
    }
}
