<?php

namespace Database\Factories;

use App\Enums\AccessPurpose;
use App\Enums\JustificationStatus;
use App\Models\AccessJustification;
use App\Models\CriminalCase;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccessJustification>
 */
class AccessJustificationFactory extends Factory
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
            'action' => AccessJustification::ACTION_SEARCH_NAME,
            'purpose' => AccessPurpose::AssignedInvestigation,
            'status' => JustificationStatus::Recorded,
        ];
    }

    /**
     * A request for access to a case, awaiting a decision.
     */
    public function pendingFor(CriminalCase $case): static
    {
        return $this->state(fn (array $attributes) => [
            'action' => AccessJustification::ACTION_CASE_ACCESS,
            'case_id' => $case->id,
            'case_reference' => $case->reference,
            'explanation' => 'Synthetic request for testing.',
            'status' => JustificationStatus::Pending,
        ]);
    }
}
