<?php

namespace App\Models;

use App\Enums\DuplicateStatus;
use Database\Factories\DuplicateCandidateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Two person records that may be the same person, for a human to review.
 *
 * @property int $id
 * @property string $first_person_id
 * @property string $second_person_id
 * @property string $reason
 * @property int $confidence
 * @property DuplicateStatus $status
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property string|null $review_note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Person $firstPerson
 * @property-read Person $secondPerson
 */
#[Fillable(['first_person_id', 'second_person_id', 'reason', 'confidence'])]
class DuplicateCandidate extends Model
{
    /** @use HasFactory<DuplicateCandidateFactory> */
    use HasFactory;

    public const REASON_IDENTITY = 'same_identity_number';

    public const REASON_NAME_AND_YEAR = 'same_name_and_birth_year';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DuplicateStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function firstPerson(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'first_person_id');
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function secondPerson(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'second_person_id');
    }
}
