<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Provisional: the identity documents collected must be approved (§3.1).
 */
enum IdentityType: string
{
    use HasOptions;

    case NationalId = 'national_id';
    case Passport = 'passport';
    case DrivingPermit = 'driving_permit';

    public function label(): string
    {
        return match ($this) {
            self::NationalId => 'National ID (NIN)',
            self::Passport => 'Passport',
            self::DrivingPermit => 'Driving permit',
        };
    }
}
