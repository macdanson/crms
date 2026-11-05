<?php

namespace App\Models;

use App\Enums\CourtProceedingStatus;
use Database\Factories\CourtProceedingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $case_id
 * @property string $court_name
 * @property string $court_reference
 * @property CourtProceedingStatus $status
 * @property Carbon|null $filed_on
 * @property int $recorded_by
 * @property-read CriminalCase $criminalCase
 */
class CourtProceeding extends Model
{
    /** @use HasFactory<CourtProceedingFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CourtProceedingStatus::class,
            'filed_on' => 'date',
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
     * @return HasMany<CourtOutcome, $this>
     */
    public function outcomes(): HasMany
    {
        return $this->hasMany(CourtOutcome::class);
    }
}
