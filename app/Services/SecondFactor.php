<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Events\RecoveryCodeReplaced;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\RecoveryCode;

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

    /**
     * Each retry follows another request's successful change, so this is
     * only reached if the codes change continuously.
     */
    private const MAX_SWAP_ATTEMPTS = 50;

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
     * Whether the code is one of the user's unused recovery codes, read
     * afresh. Does not use it up.
     */
    public function hasRecoveryCode(User $user, string $recoveryCode): bool
    {
        $storedCodes = User::query()->whereKey($user->getKey())->value('two_factor_recovery_codes');

        if (! is_string($storedCodes)) {
            return false;
        }

        $recoveryCode = trim($recoveryCode);

        return collect($this->decodeCodes($storedCodes))
            ->contains(fn (string $code): bool => hash_equals($code, $recoveryCode));
    }

    /**
     * The codes held in the stored (encrypted) recovery codes value.
     *
     * @return list<string>
     */
    private function decodeCodes(string $storedCodes): array
    {
        $codes = json_decode(Fortify::currentEncrypter()->decrypt($storedCodes), true);

        return is_array($codes) ? array_values(array_filter($codes, is_string(...))) : [];
    }

    /**
     * Use up a recovery code if it is still unused, replacing it with a new
     * one. Returns false when the code is not (or no longer) one of the
     * user's.
     *
     * The replacement is saved only if the stored codes are still the ones
     * that were read (compare-and-swap), so two requests giving the same
     * code cannot both succeed, at sign-in or in settings.
     *
     * A failed swap means another request changed the codes first, so it is
     * retried against the new codes: false then always means the code is
     * really not there, never that the request lost a race.
     *
     * @throws ValidationException if the codes keep changing (a safety net),
     *                             asking the user to try again.
     */
    public function consumeRecoveryCode(User $user, string $recoveryCode): bool
    {
        $recoveryCode = trim($recoveryCode);
        $encrypter = Fortify::currentEncrypter();

        for ($attempt = 0; $attempt < self::MAX_SWAP_ATTEMPTS; $attempt++) {
            $storedCodes = User::query()->whereKey($user->getKey())->value('two_factor_recovery_codes');

            if (! is_string($storedCodes)) {
                return false;
            }

            $codes = $this->decodeCodes($storedCodes);
            $usedCode = collect($codes)->first(fn (string $code): bool => hash_equals($code, $recoveryCode));

            if ($usedCode === null) {
                return false;
            }

            $replacedCodes = $encrypter->encrypt(json_encode(
                array_map(fn (string $code): string => $code === $usedCode ? RecoveryCode::generate() : $code, $codes),
            ));

            $updated = User::query()
                ->whereKey($user->getKey())
                ->where('two_factor_recovery_codes', $storedCodes)
                ->update(['two_factor_recovery_codes' => $replacedCodes]);

            if ($updated === 1) {
                $user->forceFill(['two_factor_recovery_codes' => $replacedCodes])->syncOriginalAttribute('two_factor_recovery_codes');
                $user->justUsedRecoveryCode = $usedCode;

                RecoveryCodeReplaced::dispatch($user, $usedCode);

                return true;
            }
        }

        throw ValidationException::withMessages([
            'recovery_code' => 'Your recovery codes changed while this code was being checked. Please try again.',
        ]);
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
