<?php

namespace App\Actions\Fortify;

use App\Models\User;
use App\Services\SecondFactor;
use Illuminate\Support\Collection;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication as FortifyEnableTwoFactorAuthentication;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\RecoveryCode;

/**
 * Fortify's "force" option replaces the secret of an already-confirmed
 * authenticator without clearing its confirmation, which would lock the user
 * out. A new secret is therefore saved only while none is confirmed, decided
 * in one conditional update, so a confirmation made a moment ago also holds.
 *
 * The session that creates a secret is the one allowed to see and confirm it
 * (SecondFactor::holdEnrolment).
 */
class EnableTwoFactorAuthentication extends FortifyEnableTwoFactorAuthentication
{
    /**
     * @param  User  $user
     * @param  bool  $force
     */
    public function __invoke($user, $force = false): void
    {
        if (! empty($user->two_factor_secret) && $force !== true) {
            return;
        }

        $encrypter = Fortify::currentEncrypter();

        $updated = User::query()
            ->whereKey($user->getKey())
            ->whereNull('two_factor_confirmed_at')
            ->update([
                'two_factor_secret' => $encrypter->encrypt($this->provider->generateSecretKey()),
                'two_factor_recovery_codes' => $encrypter->encrypt(json_encode(Collection::times(8, fn (): string => RecoveryCode::generate())->all())),
                'updated_at' => now(),
            ]);

        $user->refresh();

        if ($updated !== 1) {
            return;
        }

        if (request()->hasSession()) {
            app(SecondFactor::class)->holdEnrolment(request()->session(), $user);
        }

        TwoFactorAuthenticationEnabled::dispatch($user);
    }
}
