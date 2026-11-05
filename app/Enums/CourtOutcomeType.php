<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Judicial outcomes, kept apart from police status (§4.3). Provisional: the
 * catalogue and its definitions must be approved by legal stakeholders.
 */
enum CourtOutcomeType: string
{
    use HasOptions;

    case Pending = 'pending';
    case Acquitted = 'acquitted';
    case Convicted = 'convicted';
    case Dismissed = 'dismissed';
    case Withdrawn = 'withdrawn';
    case AppealPending = 'appeal_pending';
    case ConvictionOverturned = 'conviction_overturned';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Acquitted => 'Acquitted',
            self::Convicted => 'Convicted',
            self::Dismissed => 'Dismissed',
            self::Withdrawn => 'Withdrawn',
            self::AppealPending => 'Appeal pending',
            self::ConvictionOverturned => 'Conviction overturned',
        };
    }
}
