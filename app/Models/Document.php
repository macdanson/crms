<?php

namespace App\Models;

use App\Enums\DocumentCategory;
use App\Enums\ScanStatus;
use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A file attached to a case. The file itself is encrypted on a private disk
 * and only ever served through DocumentController (developer guide §19).
 *
 * @property string $id
 * @property string $case_id
 * @property DocumentCategory $category
 * @property string $original_name
 * @property string $mime_type
 * @property int $size_bytes
 * @property string $sha256
 * @property string $storage_key
 * @property ScanStatus $scan_status
 * @property Carbon|null $scanned_at
 * @property bool $legal_hold
 * @property int $uploaded_by
 * @property Carbon|null $created_at
 * @property-read CriminalCase $criminalCase
 * @property-read User $uploadedBy
 */
#[Hidden(['storage_key'])]
class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory, HasUuids;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => DocumentCategory::class,
            'scan_status' => ScanStatus::class,
            'scanned_at' => 'datetime',
            'legal_hold' => 'boolean',
        ];
    }

    /**
     * Whether the file may be given to anyone: it passed a scan, or scanning
     * is switched off and the configuration allows unscanned files.
     */
    public function isDownloadable(): bool
    {
        return $this->scan_status === ScanStatus::Clean
            || ($this->scan_status === ScanStatus::NotScanned && (bool) config('crms.documents.allow_unscanned'));
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
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
