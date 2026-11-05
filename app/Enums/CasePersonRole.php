<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * A person's role in one case (§4.2). Provisional.
 */
enum CasePersonRole: string
{
    use HasOptions;

    case Suspect = 'suspect';
    case Arrested = 'arrested';
    case Accused = 'accused';
    case Complainant = 'complainant';
    case Victim = 'victim';
    case Witness = 'witness';

    public function label(): string
    {
        return match ($this) {
            self::Suspect => 'Suspect',
            self::Arrested => 'Arrested person',
            self::Accused => 'Accused person',
            self::Complainant => 'Complainant',
            self::Victim => 'Victim',
            self::Witness => 'Witness',
        };
    }
}
