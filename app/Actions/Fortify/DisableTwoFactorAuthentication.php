<?php

namespace App\Actions\Fortify;

use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication as FortifyDisableTwoFactorAuthentication;

/**
 * Stops officers switching off multi-factor authentication while the
 * institution requires it. Officers who lose their device use the controlled
 * "reset authenticator" flow, which forces immediate re-enrolment.
 */
class DisableTwoFactorAuthentication extends FortifyDisableTwoFactorAuthentication
{
    /**
     * @param  mixed  $user
     *
     * @throws ValidationException
     */
    public function __invoke($user): void
    {
        if (config('crms.auth.require_mfa')) {
            throw ValidationException::withMessages([
                'two_factor' => 'Multi-factor authentication is mandatory and cannot be switched off.',
            ]);
        }

        parent::__invoke($user);
    }
}
