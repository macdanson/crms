<?php

namespace Database\Factories;

use App\Models\OffenceCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OffenceCategory>
 */
class OffenceCategoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'TEST-'.fake()->unique()->numerify('####'),
            'name' => 'Synthetic category '.fake()->unique()->numerify('####'),
            'is_active' => true,
        ];
    }
}
