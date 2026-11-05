<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $source_person_id
 * @property string $target_person_id
 * @property int $duplicate_candidate_id
 * @property array<string, list<int>> $moved_records
 * @property string $reason
 * @property int $merged_by
 * @property Carbon $merged_at
 */
class PersonMerge extends Model
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
            'moved_records' => 'array',
            'merged_at' => 'datetime',
        ];
    }
}
