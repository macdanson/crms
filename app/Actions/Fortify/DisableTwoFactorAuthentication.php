<?php

namespace App\Actions\Fortify;

use App\Services\SessionPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication as FortifyDisableTwoFactorAuthentication;

/**
 * Stops users switching off multi-factor authentication while the
 * institution requires it. Users who lose their device use the controlled
 * "reset authenticator" flow, which forces immediate re-enrolment.
 *
 * Where it is optional, switching it off needs a recent proof of the second
 * factor (RequireSecondFactorConfirmation) and signs out other sessions,
 * like replacing the authenticator.
 */
class DisableTwoFactorAuthentication extends FortifyDisableTwoFactorAuthentication
{
    /**
     * @throws ValidationException
     */
    public function __invoke(mixed $user): void
    {
        if (config('crms.auth.require_mfa')) {
            throw ValidationException::withMessages([
                'two_factor' => 'Multi-factor authentication is mandatory and cannot be switched off.',
            ]);
        }

        DB::transaction(function () use ($user): void {
            parent::__invoke($user);

            app(SessionPolicy::class)->endOtherSessions($user, request()->hasSession() ? request()->session() : null);
        });
    }
}
