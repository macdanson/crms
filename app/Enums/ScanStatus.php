<?php

namespace App\Enums;

enum ScanStatus: string
{
    case Pending = 'pending';
    case Clean = 'clean';
    case Infected = 'infected';
    case Failed = 'failed';
    case NotScanned = 'not_scanned';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Awaiting malware scan',
            self::Clean => 'Scanned: clean',
            self::Infected => 'Quarantined: malware found',
            self::Failed => 'Scan failed',
            self::NotScanned => 'Not scanned',
        };
    }
}
