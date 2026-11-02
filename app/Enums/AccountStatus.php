<?php

namespace App\Enums;

/**
 * Lifecycle state of a user account.
 *
 * Accounts are provisioned as Pending and only become Active once the user
 * has proven ownership of their email address by following an activation link.
 */
enum AccountStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Suspended = 'suspended';
    case Deactivated = 'deactivated';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending activation',
            self::Active => 'Active',
            self::Suspended => 'Suspended',
            self::Deactivated => 'Deactivated',
        };
    }

    public function canAuthenticate(): bool
    {
        return $this === self::Active;
    }
}
