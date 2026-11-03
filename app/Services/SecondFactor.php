<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
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

        // A secret that cannot be decrypted (for example after a key change)
        // matches no code; it is reported, not an error for the user.
        try {
            $secret = Fortify::currentEncrypter()->decrypt($user->two_factor_secret);
        } catch (DecryptException $exception) {
            report($exception);

            return false;
        }

        return $this->provider->verify($secret, (string) preg_replace('/\s+/', '', $code));
    }

    /**
     * How many unused recovery codes the user has, read safely: codes that
     * cannot be read count as none.
     */
    public function remainingRecoveryCodes(User $user): int
    {
        return count($this->storedCodes($user)['codes'] ?? []);
    }

    /**
     * Whether the code is one of the user's unused recovery codes, read
     * afresh. Does not use it up.
     */
    public function hasRecoveryCode(User $user, string $recoveryCode): bool
    {
        $stored = $this->storedCodes($user);

        return $stored !== null && $this->matchingCode($stored['codes'], $recoveryCode) !== null;
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
        for ($attempt = 0; $attempt < self::MAX_SWAP_ATTEMPTS; $attempt++) {
            $stored = $this->storedCodes($user);

            if ($stored === null) {
                return false;
            }

            $usedCode = $this->matchingCode($stored['codes'], $recoveryCode);

            if ($usedCode === null) {
                return false;
            }

            $replacedCodes = Fortify::currentEncrypter()->encrypt(json_encode(
                array_map(fn (string $code): string => $code === $usedCode ? RecoveryCode::generate() : $code, $stored['codes']),
            ));

            $updated = User::query()
                ->whereKey($user->getKey())
                ->where('two_factor_recovery_codes', $stored['encrypted'])
                ->update(['two_factor_recovery_codes' => $replacedCodes]);

            if ($updated === 1) {
                $user->forceFill(['two_factor_recovery_codes' => $replacedCodes])->syncOriginalAttribute('two_factor_recovery_codes');

                RecoveryCodeReplaced::dispatch($user, $usedCode);

                return true;
            }
        }

        throw ValidationException::withMessages([
            'recovery_code' => 'Your recovery codes changed while this code was being checked. Please try again.',
        ]);
    }

    /**
     * The user's recovery codes as stored now (decoded by Fortify), with the
     * encrypted value they were read from. Null when there are none.
     *
     * @return array{encrypted: string, codes: list<string>}|null
     */
    private function storedCodes(User $user): ?array
    {
        // The raw stored value, untouched by model casts: the swap compares it.
        $encrypted = User::query()->toBase()->where($user->getKeyName(), $user->getKey())->value('two_factor_recovery_codes');

        if (! is_string($encrypted)) {
            return null;
        }

        // Decoded as Fortify stores them. Fortify's recoveryCodes() is not used
        // because it assumes a readable JSON list; a value that cannot be
        // decrypted (for example after a key change) or is not a list counts
        // as no codes, reported but not an error for the user.
        try {
            $codes = json_decode(Fortify::currentEncrypter()->decrypt($encrypted), true);
        } catch (DecryptException $exception) {
            report($exception);

            return null;
        }

        return [
            'encrypted' => $encrypted,
            'codes' => is_array($codes) ? array_values(array_filter($codes, is_string(...))) : [],
        ];
    }

    /**
     * @param  list<string>  $codes
     */
    private function matchingCode(array $codes, string $recoveryCode): ?string
    {
        $recoveryCode = trim($recoveryCode);

        return collect($codes)->first(fn (string $code): bool => hash_equals($code, $recoveryCode));
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
