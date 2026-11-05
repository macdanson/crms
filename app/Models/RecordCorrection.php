<?php

namespace App\Models;

use App\Casts\ProtectedText;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One corrected field. Written once and never changed (§13.1).
 *
 * @property int $id
 * @property string $record_type
 * @property string $record_id
 * @property string $field
 * @property string|null $previous_value_ciphertext
 * @property string|null $new_value_ciphertext
 * @property string $reason
 * @property int $corrected_by
 * @property Carbon $corrected_at
 * @property-read User $correctedBy
 */
#[Hidden(['previous_value_ciphertext', 'new_value_ciphertext'])]
class RecordCorrection extends Model
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
            'previous_value_ciphertext' => ProtectedText::class,
            'new_value_ciphertext' => ProtectedText::class,
            'corrected_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Record corrections are append-only.');
        });

        static::deleting(function (): never {
            throw new LogicException('Record corrections are append-only.');
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function correctedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'corrected_by');
    }
}
