<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\Station;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Generates synthetic departments only (developer guide §24).
 *
 * @extends Factory<Department>
 */
class DepartmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'station_id' => Station::factory(),
            'name' => 'Test Unit '.fake()->unique()->numerify('####'),
            'is_active' => true,
        ];
    }
}
