<?php

namespace App\Enums;

enum OutcomeVerification: string
{
    case Pending = 'pending';
    case Verified = 'verified';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Awaiting verification',
            self::Verified => 'Verified',
            self::Rejected => 'Rejected',
        };
    }
}
