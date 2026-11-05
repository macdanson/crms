<?php

namespace App\Models;

use App\Enums\AccessPurpose;
use App\Enums\JustificationStatus;
use Database\Factories\AccessJustificationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The stated purpose of a sensitive action and, for exceptional access to a
 * case, the decision on it (developer guide §8).
 *
 * @property string $id
 * @property int $user_id
 * @property string $action
 * @property AccessPurpose $purpose
 * @property string|null $case_reference
 * @property string|null $case_id
 * @property string|null $explanation
 * @property JustificationStatus $status
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property string|null $decision_note
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property-read User $user
 * @property-read User|null $decidedBy
 * @property-read CriminalCase|null $criminalCase
 */
class AccessJustification extends Model
{
    /** @use HasFactory<AccessJustificationFactory> */
    use HasFactory, HasUuids;

    public const ACTION_CASE_ACCESS = 'case_access';

    public const ACTION_SEARCH_IDENTITY = 'search_identity';

    public const ACTION_SEARCH_NAME = 'search_name';

    public const ACTION_REVEAL = 'reveal';

    public const ACTION_EXPORT = 'export';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purpose' => AccessPurpose::class,
            'status' => JustificationStatus::class,
            'decided_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * Approved exceptional access that has not yet ended.
     *
     * @param  Builder<self>  $query
     */
    public function scopeActiveGrantFor(Builder $query, User $user): void
    {
        $query->where('user_id', $user->getKey())
            ->where('action', self::ACTION_CASE_ACCESS)
            ->where('status', JustificationStatus::Approved)
            ->where('expires_at', '>', now());
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * @return BelongsTo<CriminalCase, $this>
     */
    public function criminalCase(): BelongsTo
    {
        return $this->belongsTo(CriminalCase::class, 'case_id');
    }
}
