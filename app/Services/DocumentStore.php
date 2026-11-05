<?php

namespace App\Services;

use App\Enums\AuditOutcome;
use App\Enums\CaseEventType;
use App\Enums\DocumentCategory;
use App\Enums\ScanStatus;
use App\Jobs\ScanDocument;
use App\Models\CriminalCase;
use App\Models\Document;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Keeps case documents encrypted on a private disk under random names
 * (developer guide §19).
 */
class DocumentStore
{
    public function __construct(
        private DataProtection $protection,
        private AuditLogger $audit,
        private CaseRecords $cases,
    ) {}

    public function store(CriminalCase $case, UploadedFile $file, DocumentCategory $category, User $actor): Document
    {
        $contents = (string) file_get_contents($file->getRealPath());
        $storageKey = now()->format('Y/m/').Str::random(48);

        $this->disk()->put($storageKey, $this->protection->encrypt($contents));

        $document = DB::transaction(function () use ($case, $file, $category, $actor, $contents, $storageKey): Document {
            $document = new Document;
            $document->forceFill([
                'case_id' => $case->id,
                'category' => $category,
                'original_name' => $this->safeName($file->getClientOriginalName()),
                'mime_type' => (string) $file->getMimeType(),
                'size_bytes' => strlen($contents),
                'sha256' => hash('sha256', $contents),
                'storage_key' => $storageKey,
                'scan_status' => ScanStatus::Pending,
                'uploaded_by' => $actor->getKey(),
            ])->save();

            $this->cases->addHistory($case, CaseEventType::DocumentAdded, "Document added ({$category->label()}).", $actor, ['document_id' => $document->id]);
            $this->audit->record('document.uploaded', AuditOutcome::Success, $actor, ['case_id' => $case->id, 'category' => $category->value], $document);

            return $document;
        });

        ScanDocument::dispatch($document->id);

        return $document;
    }

    public function contents(Document $document): string
    {
        return $this->protection->decrypt((string) $this->disk()->get($document->storage_key));
    }

    /**
     * A name that is safe to keep and to send back in a header: no path, no
     * control characters, and short.
     */
    private function safeName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = (string) preg_replace('/[^\pL\pN\s._\-()]/u', '_', $name);

        return Str::limit(trim($name), 200, '') ?: 'document';
    }

    private function disk(): Filesystem
    {
        return Storage::disk((string) config('crms.documents.disk'));
    }
}
