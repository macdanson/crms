<?php

namespace App\Models;

use App\Casts\ProtectedText;
use App\Enums\CaseEventType;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * An entry in a case's history. Written once and never changed (§13.1).
 *
 * @property int $id
 * @property string $case_id
 * @property int $user_id
 * @property CaseEventType $type
 * @property string $summary_ciphertext The summary, decrypted by the cast.
 * @property array<string, mixed>|null $meta
 * @property Carbon $occurred_at
 * @property-read User $user
 */
#[Hidden(['summary_ciphertext'])]
class InvestigationEvent extends Model
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
            'type' => CaseEventType::class,
            'summary_ciphertext' => ProtectedText::class,
            'meta' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Investigation events are append-only.');
        });

        static::deleting(function (): never {
            throw new LogicException('Investigation events are append-only.');
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
