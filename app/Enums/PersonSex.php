<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum PersonSex: string
{
    use HasOptions;

    case Female = 'female';
    case Male = 'male';
    case NotRecorded = 'not_recorded';

    public function label(): string
    {
        return match ($this) {
            self::Female => 'Female',
            self::Male => 'Male',
            self::NotRecorded => 'Not recorded',
        };
    }
}
