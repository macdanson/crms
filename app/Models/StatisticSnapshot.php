<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One aggregate count in the analytics layer. Written only by
 * App\Services\Statistics.
 *
 * @property int $id
 * @property Carbon $period
 * @property int $district_id
 * @property int $offence_category_id
 * @property string $status
 * @property int $case_count
 * @property Carbon $generated_at
 */
class StatisticSnapshot extends Model
{
    public $timestamps = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'generated_at' => 'datetime',
        ];
    }
}
