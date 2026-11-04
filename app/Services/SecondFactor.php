<?php

namespace App\Services;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Session\Session;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Events\RecoveryCodeReplaced;
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

    /**
     * Session key marking the new secret this session was shown.
     */
    public const ENROLMENT_SECRET = 'crms.enrolment_secret';

    /**
     * Session key asking the setup page to show the recovery codes. Not a
     * flash: another request at the same moment must not use it up.
     */
    public const SHOW_RECOVERY_CODES = 'crms.show_recovery_codes';

    /**
     * Each retry follows another request's successful change, so this is
     * only reached if the codes change continuously.
     */
    private const MAX_SWAP_ATTEMPTS = 50;

    public function __construct(
        private TwoFactorAuthenticationProvider $provider,
        private KeyedHash $keyedHash,
    ) {}

    /**
     * Whether the code is the one the user's authenticator shows now.
     */
    public function isValidCode(User $user, #[\SensitiveParameter] string $code): bool
    {
        // No secret, or one that cannot be read, matches no code.
        $secret = $user->twoFactorSecret();

        return $secret !== null && $this->provider->verify($secret, (string) preg_replace('/\s+/', '', $code));
    }

    /**
     * Whether the code is one of the user's unused recovery codes, read
     * afresh. Does not use it up.
     */
    public function hasRecoveryCode(User $user, #[\SensitiveParameter] string $recoveryCode): bool
    {
        $stored = $this->storedCodes($user);

        return $stored !== null && $this->matchingCode($stored['codes'], $recoveryCode) !== null;
    }

    /**
     * Use up a recovery code if it is still unused, removing it. (Fortify
     * replaces it with a new one the user never sees, so the number left
     * would never fall and would say nothing about the codes they hold.)
     * Returns false when the code is not (or no longer) one of the user's.
     *
     * @throws ValidationException if the codes keep changing (see useRecoveryCode).
     */
    public function consumeRecoveryCode(User $user, #[\SensitiveParameter] string $recoveryCode): bool
    {
        return $this->useRecoveryCode($user, $recoveryCode, function (string $storedCodes, string $usedCode, array $codes) use ($user): bool {
            $remainingCodes = Fortify::currentEncrypter()->encrypt(json_encode(
                array_values(array_filter($codes, fn (string $code): bool => $code !== $usedCode)),
            ));

            $updated = User::query()
                ->whereKey($user->getKey())
                ->where('two_factor_recovery_codes', $storedCodes)
                ->update(['two_factor_recovery_codes' => $remainingCodes]);

            if ($updated !== 1) {
                return false;
            }

            $user->forceFill(['two_factor_recovery_codes' => $remainingCodes])->syncOriginalAttribute('two_factor_recovery_codes');

            RecoveryCodeReplaced::dispatch($user, $usedCode);

            return true;
        });
    }

    /**
     * Use a recovery code up through $write, which must change the stored
     * codes only while they are still $storedCodes (compare-and-swap) and
     * return whether it did. Returns false when the code is not (or no
     * longer) one of the user's.
     *
     * So two requests giving the same code cannot both succeed. A failed
     * swap means another request changed the codes first, so it is retried
     * against the new codes: false then always means the code is really not
     * there, never that the request lost a race.
     *
     * @param  Closure(string $storedCodes, string $usedCode, list<string> $codes): bool  $write
     *
     * @throws ValidationException if the codes keep changing (a safety net),
     *                             asking the user to try again.
     */
    public function useRecoveryCode(User $user, #[\SensitiveParameter] string $recoveryCode, Closure $write): bool
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

            if ($write($stored['encrypted'], $usedCode, $stored['codes'])) {
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
        // The raw stored value, untouched by model casts: the swap compares
        // it. A locking read sees the latest committed value even inside a
        // caller's transaction, where a plain read could keep returning the
        // codes as they were when it began, so every retry would fail.
        $encrypted = User::query()->toBase()->where($user->getKeyName(), $user->getKey())->lockForUpdate()->value('two_factor_recovery_codes');

        if (! is_string($encrypted)) {
            return null;
        }

        return [
            'encrypted' => $encrypted,
            'codes' => User::decodeRecoveryCodes($encrypted),
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
     * Mark this session as the one that was shown the user's new secret. Only
     * it may see the secret again or confirm it: a session that knows only
     * the password must not end up holding a copy of the second factor.
     */
    public function holdEnrolment(Session $session, User $user): void
    {
        $session->put(self::ENROLMENT_SECRET, $this->keyedHash->of((string) $user->getRawOriginal('two_factor_secret')));
    }

    public function holdsEnrolment(Session $session, User $user): bool
    {
        $held = $session->get(self::ENROLMENT_SECRET);

        return is_string($held) && hash_equals($held, $this->keyedHash->of((string) $user->getRawOriginal('two_factor_secret')));
    }

    /**
     * The secret is confirmed: stop marking it, and have the setup page show
     * the recovery codes once.
     */
    public function finishEnrolment(Session $session): void
    {
        $session->forget(self::ENROLMENT_SECRET);
        $session->put(self::SHOW_RECOVERY_CODES, true);
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
