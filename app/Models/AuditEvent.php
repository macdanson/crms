<?php

namespace App\Models;

use App\Enums\AuditOutcome;
use Database\Factories\AuditEventFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * An append-only security or business audit record.
 *
 * Records are also streamed to the "audit" log channel so they can be shipped
 * to a separate, access-controlled audit platform (developer guide §12.3).
 *
 * @property string $id
 * @property Carbon $occurred_at
 * @property int|null $user_id
 * @property string $event
 * @property AuditOutcome $outcome
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string|null $session_hash
 * @property string|null $request_id
 * @property array<string, mixed>|null $context
 */
class AuditEvent extends Model
{
    /** @use HasFactory<AuditEventFactory> */
    use HasFactory, HasUuids;

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'outcome' => AuditOutcome::class,
            'context' => 'array',
        ];
    }

    /**
     * Audit records may be written once and never changed or removed.
     */
    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Audit events are append-only.');
        });

        static::deleting(function (): never {
            throw new LogicException('Audit events are append-only.');
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
