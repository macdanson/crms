<?php

namespace App\Models;

use Database\Factories\CaseAssignmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $case_id
 * @property int $user_id
 * @property bool $is_lead
 * @property int $assigned_by
 * @property Carbon $assigned_at
 * @property Carbon|null $ended_at
 * @property int|null $ended_by
 * @property-read CriminalCase $criminalCase
 * @property-read User $user
 */
class CaseAssignment extends Model
{
    /** @use HasFactory<CaseAssignmentFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_lead' => 'boolean',
            'assigned_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<CriminalCase, $this>
     */
    public function criminalCase(): BelongsTo
    {
        return $this->belongsTo(CriminalCase::class, 'case_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
