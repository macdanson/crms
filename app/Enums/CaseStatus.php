<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Police operational status only (§4.3). Closing a case administratively says
 * nothing about conviction or acquittal; see App\Enums\CourtOutcomeType.
 * Provisional: the workflow must be confirmed with operational stakeholders.
 */
enum CaseStatus: string
{
    use HasOptions;

    case Open = 'open';
    case UnderInvestigation = 'under_investigation';
    case Submitted = 'submitted';
    case ClosedAdministratively = 'closed_administratively';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::UnderInvestigation => 'Under investigation',
            self::Submitted => 'Submitted',
            self::ClosedAdministratively => 'Closed administratively',
        };
    }

    /**
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Open => [self::UnderInvestigation, self::ClosedAdministratively],
            self::UnderInvestigation => [self::Submitted, self::ClosedAdministratively],
            self::Submitted => [self::UnderInvestigation, self::ClosedAdministratively],
            self::ClosedAdministratively => [self::UnderInvestigation],
        };
    }
}
