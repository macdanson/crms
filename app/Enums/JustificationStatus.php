<?php

namespace App\Enums;

enum JustificationStatus: string
{
    case Recorded = 'recorded';
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Recorded => 'Recorded',
            self::Pending => 'Awaiting decision',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
        };
    }
}
