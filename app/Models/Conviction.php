<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Created only by App\Services\CourtRecords, from a verified outcome (§4.3).
 *
 * @property int $id
 * @property int $court_outcome_id
 * @property int $case_person_id
 * @property string $person_id
 * @property string $case_id
 * @property Carbon $convicted_on
 * @property Carbon|null $overturned_at
 * @property int|null $overturned_by_outcome_id
 * @property Carbon|null $created_at
 */
class Conviction extends Model
{
    public const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'convicted_on' => 'date',
            'overturned_at' => 'datetime',
        ];
    }
}
