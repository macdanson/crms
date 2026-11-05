<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum AddressType: string
{
    use HasOptions;

    case Home = 'home';
    case Work = 'work';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Home => 'Home',
            self::Work => 'Work',
            self::Other => 'Other',
        };
    }
}
