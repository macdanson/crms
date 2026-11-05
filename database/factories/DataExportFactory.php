<?php

namespace Database\Factories;

use App\Enums\ExportStatus;
use App\Models\AccessJustification;
use App\Models\DataExport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DataExport>
 */
class DataExportFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'requested_by' => User::factory(),
            'justification_id' => fn (array $attributes) => AccessJustification::factory()->create([
                'user_id' => $attributes['requested_by'],
                'action' => AccessJustification::ACTION_EXPORT,
            ])->id,
            'filters' => [],
            'fields' => config('crms.exports.fields'),
            'row_count' => 1,
            'status' => ExportStatus::PendingApproval,
        ];
    }
}
