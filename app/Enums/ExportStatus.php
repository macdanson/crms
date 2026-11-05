<?php

namespace App\Enums;

enum ExportStatus: string
{
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Ready = 'ready';
    case Downloaded = 'downloaded';
    case Expired = 'expired';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::PendingApproval => 'Awaiting approval',
            self::Approved => 'Approved, being prepared',
            self::Rejected => 'Rejected',
            self::Ready => 'Ready to download',
            self::Downloaded => 'Downloaded',
            self::Expired => 'Expired',
            self::Failed => 'Could not be prepared',
        };
    }
}
