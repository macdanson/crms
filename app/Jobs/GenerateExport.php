<?php

namespace App\Jobs;

use App\Enums\ExportStatus;
use App\Models\DataExport;
use App\Services\DataExports;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Prepares an export. It carries only the export's ID, and DataExports checks
 * the requester's access again when it runs (developer guide §22.2).
 */
class GenerateExport implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public string $exportId) {}

    /**
     * Execute the job.
     */
    public function handle(DataExports $exports): void
    {
        $exports->generate(DataExport::query()->findOrFail($this->exportId));
    }

    public function failed(?Throwable $exception): void
    {
        DataExport::query()->whereKey($this->exportId)->update(['status' => ExportStatus::Failed]);
    }
}
