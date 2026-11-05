<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum ContactType: string
{
    use HasOptions;

    case Phone = 'phone';
    case Email = 'email';

    public function label(): string
    {
        return match ($this) {
            self::Phone => 'Phone',
            self::Email => 'Email',
        };
    }
}
