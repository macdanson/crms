<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RoleAssignment>
 */
class RoleAssignmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'role' => Role::RecordsOfficer,
            'reason' => 'Synthetic grant for testing.',
            'granted_at' => now(),
        ];
    }
}
