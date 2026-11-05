<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Provisional: definitions must be approved by Police and legal stakeholders.
 */
enum CourtProceedingStatus: string
{
    use HasOptions;

    case Filed = 'filed';
    case InProgress = 'in_progress';
    case Adjourned = 'adjourned';
    case Concluded = 'concluded';

    public function label(): string
    {
        return match ($this) {
            self::Filed => 'Filed',
            self::InProgress => 'In progress',
            self::Adjourned => 'Adjourned',
            self::Concluded => 'Concluded',
        };
    }
}
