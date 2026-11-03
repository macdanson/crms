<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;

/**
 * Checks a user's second factor (an authenticator code or a recovery code)
 * and remembers, for a few minutes, that the session has just proved it.
 *
 * Signing in does not count as proof: someone holding a stolen session
 * cookie must still not be able to manage the authenticator.
 */
class SecondFactor
{
    /**
     * Session key holding when the second factor was last proved.
     */
    public const CONFIRMED_AT = 'crms.second_factor_confirmed_at';

    /**
     * How long a proof lasts, in seconds.
     */
    public const CONFIRMATION_SECONDS = 300;

    public function __construct(private TwoFactorAuthenticationProvider $provider) {}

    /**
     * Whether the code is the one the user's authenticator shows now.
     */
    public function isValidCode(User $user, string $code): bool
    {
        if ($user->two_factor_secret === null) {
            return false;
        }

        return $this->provider->verify(
            Fortify::currentEncrypter()->decrypt($user->two_factor_secret),
            (string) preg_replace('/\s+/', '', $code),
        );
    }

    /**
     * The user's unused recovery code that matches, or null.
     */
    public function matchingRecoveryCode(User $user, string $recoveryCode): ?string
    {
        if ($user->two_factor_recovery_codes === null) {
            return null;
        }

        $recoveryCode = trim($recoveryCode);

        return collect($user->recoveryCodes())
            ->first(fn (string $code): bool => hash_equals($code, $recoveryCode));
    }

    /**
     * Record that the session has just proved the second factor.
     */
    public function markConfirmed(Session $session): void
    {
        $session->put(self::CONFIRMED_AT, now()->getTimestamp());
    }

    public function isRecentlyConfirmed(Session $session): bool
    {
        $confirmedAt = (int) $session->get(self::CONFIRMED_AT, 0);

        return now()->getTimestamp() - $confirmedAt < self::CONFIRMATION_SECONDS;
    }
}
