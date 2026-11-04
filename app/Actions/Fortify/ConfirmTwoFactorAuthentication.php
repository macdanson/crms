<?php

namespace App\Actions\Fortify;

use App\Enums\AuditOutcome;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\SecondFactor;
use App\Services\SessionPolicy;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication as FortifyConfirmTwoFactorAuthentication;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;

/**
 * Fortify's enrolment confirmation, except that:
 *
 * - it confirms only the secret the code was checked against, so a secret
 *   replaced meanwhile is never confirmed by a code for the old one;
 * - every other session is signed out in the same transaction. Those
 *   sessions only ever proved the password, and being signed in must not
 *   count as having given a code once the account has an authenticator;
 * - a wrong code is audited.
 *
 * Who may confirm at all is checked by RequireSecondFactorConfirmation: only
 * the session that was shown the secret.
 */
class ConfirmTwoFactorAuthentication extends FortifyConfirmTwoFactorAuthentication
{
    /**
     * @param  User  $user
     * @param  mixed  $code
     *
     * @throws ValidationException
     */
    public function __invoke($user, #[\SensitiveParameter] $code): void
    {
        $secondFactor = app(SecondFactor::class);
        $session = request()->hasSession() ? request()->session() : null;
        $secret = $user->getRawOriginal('two_factor_secret');

        $confirmed = is_string($code)
            && $secondFactor->isValidCode($user, $code)
            && $this->confirm($user, $secret, $session);

        if (! $confirmed) {
            app(AuditLogger::class)->record('mfa.enrolment.failed', AuditOutcome::Failure, $user);

            throw ValidationException::withMessages([
                'code' => [__('The provided two factor authentication code was invalid.')],
            ])->errorBag('confirmTwoFactorAuthentication');
        }

        if ($session !== null) {
            $secondFactor->finishEnrolment($session);
        }

        TwoFactorAuthenticationConfirmed::dispatch($user);
    }

    private function confirm(User $user, ?string $secret, ?Session $session): bool
    {
        return DB::transaction(function () use ($user, $secret, $session): bool {
            $updated = User::query()
                ->whereKey($user->getKey())
                ->where('two_factor_secret', $secret)
                ->whereNull('two_factor_confirmed_at')
                ->update(['two_factor_confirmed_at' => now(), 'updated_at' => now()]);

            if ($updated !== 1) {
                return false;
            }

            $user->refresh();

            app(SessionPolicy::class)->endOtherSessions($user, $session);

            return true;
        });
    }
}
