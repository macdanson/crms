<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Whether an authorised process has confirmed an identity (§5.2). Everything
 * starts Unverified: having a number on file proves nothing.
 */
enum VerificationStatus: string
{
    use HasOptions;

    case Unverified = 'unverified';
    case Submitted = 'submitted';
    case Verified = 'verified';
    case Failed = 'failed';
    case ReviewRequired = 'review_required';

    public function label(): string
    {
        return match ($this) {
            self::Unverified => 'Unverified',
            self::Submitted => 'Submitted for verification',
            self::Verified => 'Verified',
            self::Failed => 'Failed verification',
            self::ReviewRequired => 'Expired / requires review',
        };
    }
}
