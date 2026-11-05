<?php

namespace App\Models;

use App\Enums\ExportStatus;
use Database\Factories\DataExportFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $requested_by
 * @property string $justification_id
 * @property array<string, mixed> $filters
 * @property list<string> $fields
 * @property int $row_count
 * @property ExportStatus $status
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property string|null $decision_note
 * @property string|null $storage_key
 * @property Carbon|null $generated_at
 * @property Carbon|null $downloaded_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property-read User $requester
 * @property-read User|null $decidedBy
 * @property-read AccessJustification $justification
 */
#[Hidden(['storage_key'])]
class DataExport extends Model
{
    /** @use HasFactory<DataExportFactory> */
    use HasFactory, HasUuids;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'fields' => 'array',
            'status' => ExportStatus::class,
            'decided_at' => 'datetime',
            'generated_at' => 'datetime',
            'downloaded_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * @return BelongsTo<AccessJustification, $this>
     */
    public function justification(): BelongsTo
    {
        return $this->belongsTo(AccessJustification::class, 'justification_id');
    }
}
