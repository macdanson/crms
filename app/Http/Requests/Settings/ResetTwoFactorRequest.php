<?php

namespace App\Http\Requests\Settings;

/**
 * Replacing the authenticator needs proof of the current second factor (an
 * authenticator code or a recovery code), not only the password: a
 * hijacked session must not be able to take over the second factor.
 */
class ResetTwoFactorRequest extends SecondFactorRequest
{
    protected function failureEvent(): string
    {
        return 'mfa.reset.failed';
    }
}
