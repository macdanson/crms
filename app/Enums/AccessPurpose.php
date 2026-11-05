<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Why a sensitive action is being taken (developer guide §8). Provisional: the
 * final list must be approved by the institution.
 */
enum AccessPurpose: string
{
    use HasOptions;

    case AssignedInvestigation = 'assigned_investigation';
    case SupervisoryReview = 'supervisory_review';
    case RecordsQuality = 'records_quality';
    case IdentityConfirmation = 'identity_confirmation';
    case ApprovedReporting = 'approved_reporting';

    public function label(): string
    {
        return match ($this) {
            self::AssignedInvestigation => 'Assigned investigation',
            self::SupervisoryReview => 'Authorised supervisory review',
            self::RecordsQuality => 'Approved records-quality exercise',
            self::IdentityConfirmation => 'Confirming a person’s identity',
            self::ApprovedReporting => 'Approved operational reporting',
        };
    }
}
