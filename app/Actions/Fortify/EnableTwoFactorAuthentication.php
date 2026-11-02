<?php

namespace App\Actions\Fortify;

use Laravel\Fortify\Actions\EnableTwoFactorAuthentication as FortifyEnableTwoFactorAuthentication;

/**
 * Fortify's "force" option replaces the secret of an already-confirmed
 * authenticator without clearing its confirmation, which would lock the
 * user out. Forcing is therefore only allowed during enrolment.
 */
class EnableTwoFactorAuthentication extends FortifyEnableTwoFactorAuthentication
{
    /**
     * @param  mixed  $user
     * @param  bool  $force
     */
    public function __invoke($user, $force = false): void
    {
        if ($user->two_factor_confirmed_at !== null) {
            $force = false;
        }

        parent::__invoke($user, $force);
    }
}
