<?php

namespace App\Jobs;

use App\Contracts\MalwareScanner;
use App\Enums\AuditOutcome;
use App\Enums\ScanStatus;
use App\Models\Document;
use App\Services\AuditLogger;
use App\Services\DocumentStore;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Scans an uploaded file. It carries only the document's ID, never its content
 * (developer guide §22.2), and acts as the system: no user's access is involved.
 */
class ScanDocument implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public string $documentId) {}

    /**
     * Execute the job.
     */
    public function handle(MalwareScanner $scanner, DocumentStore $store, AuditLogger $audit): void
    {
        $document = Document::query()->findOrFail($this->documentId);

        $status = $scanner->scan($store->contents($document));

        $document->forceFill(['scan_status' => $status, 'scanned_at' => now()])->save();

        $audit->record(
            'document.scanned',
            $status === ScanStatus::Infected ? AuditOutcome::Blocked : AuditOutcome::Success,
            null,
            ['result' => $status->value],
            $document,
        );
    }

    /**
     * After the last retry the file stays undownloadable.
     */
    public function failed(?Throwable $exception): void
    {
        $document = Document::query()->find($this->documentId);

        if ($document === null) {
            return;
        }

        $document->forceFill(['scan_status' => ScanStatus::Failed])->save();

        app(AuditLogger::class)->record('document.scan_failed', AuditOutcome::Failure, null, record: $document);
    }
}
