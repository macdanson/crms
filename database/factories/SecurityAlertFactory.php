<?php

namespace Database\Factories;

use App\Models\SecurityAlert;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SecurityAlert>
 */
class SecurityAlertFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'rule' => 'identifier_reveals',
            'severity' => 'high',
            'event_count' => 10,
            'window_started_at' => now()->startOfHour(),
        ];
    }
}
