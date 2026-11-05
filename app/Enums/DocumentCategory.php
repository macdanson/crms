<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Provisional. These are case attachments, not an evidence chain of custody.
 */
enum DocumentCategory: string
{
    use HasOptions;

    case Statement = 'statement';
    case Report = 'report';
    case CourtDocument = 'court_document';
    case Correspondence = 'correspondence';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Statement => 'Statement',
            self::Report => 'Report',
            self::CourtDocument => 'Court document',
            self::Correspondence => 'Correspondence',
            self::Other => 'Other',
        };
    }
}
