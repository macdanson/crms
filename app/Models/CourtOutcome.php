<?php

namespace App\Models;

use App\Enums\CourtOutcomeType;
use App\Enums\OutcomeVerification;
use Database\Factories\CourtOutcomeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What a court decided about one person in one case, from a named source.
 * Nothing follows from it until a different officer has verified it.
 *
 * @property int $id
 * @property int $court_proceeding_id
 * @property int $case_person_id
 * @property CourtOutcomeType $outcome
 * @property Carbon $outcome_date
 * @property string $source
 * @property string|null $source_reference
 * @property OutcomeVerification $verification_status
 * @property int $recorded_by
 * @property Carbon $recorded_at
 * @property int|null $verified_by
 * @property Carbon|null $verified_at
 * @property string|null $verification_note
 * @property int|null $supersedes_id
 * @property-read CourtProceeding $proceeding
 * @property-read CasePerson $casePerson
 * @property-read User $recordedBy
 * @property-read User|null $verifiedBy
 */
class CourtOutcome extends Model
{
    /** @use HasFactory<CourtOutcomeFactory> */
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
            'outcome' => CourtOutcomeType::class,
            'outcome_date' => 'date',
            'verification_status' => OutcomeVerification::class,
            'recorded_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<CourtProceeding, $this>
     */
    public function proceeding(): BelongsTo
    {
        return $this->belongsTo(CourtProceeding::class, 'court_proceeding_id');
    }

    /**
     * @return BelongsTo<CasePerson, $this>
     */
    public function casePerson(): BelongsTo
    {
        return $this->belongsTo(CasePerson::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
