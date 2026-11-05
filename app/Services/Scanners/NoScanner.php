<?php

namespace App\Services\Scanners;

use App\Contracts\MalwareScanner;
use App\Enums\ScanStatus;

/**
 * For local development only. Files are marked as not scanned, and stay
 * undownloadable unless crms.documents.allow_unscanned is switched on.
 */
class NoScanner implements MalwareScanner
{
    public function scan(string $contents): ScanStatus
    {
        return ScanStatus::NotScanned;
    }
}
