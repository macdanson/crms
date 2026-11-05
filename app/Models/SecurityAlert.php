<?php

namespace App\Models;

use Database\Factories\SecurityAlertFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $rule
 * @property string $severity
 * @property int|null $user_id
 * @property int $event_count
 * @property Carbon $window_started_at
 * @property string $status
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property string|null $review_note
 * @property Carbon|null $created_at
 * @property-read User|null $user
 * @property-read User|null $reviewedBy
 */
class SecurityAlert extends Model
{
    /** @use HasFactory<SecurityAlertFactory> */
    use HasFactory;

    public const STATUS_OPEN = 'open';

    public const STATUS_REVIEWED = 'reviewed';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'window_started_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
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
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
