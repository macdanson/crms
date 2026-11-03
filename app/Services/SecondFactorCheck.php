<?php

namespace App\Services;

use App\Enums\AttemptResult;
use App\Enums\AuditOutcome;
use App\Models\User;
use App\Notifications\SignInCodesLocked;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * Limits guesses at a user's authenticator or recovery codes, per account
 * (AccountAttemptLimiter). Used by the sign-in challenge and the checks in
 * settings, like PasswordCheck is used for passwords.
 *
 * The sign-in challenge and settings are counted separately. Someone who
 * knows only the password can pause code checks at sign-in, but not in a
 * session the real user already has; the user is told when that happens,
 * so they can change their password.
 */
class SecondFactorCheck
{
    /**
     * Codes given at the sign-in challenge.
     */
    public const AT_SIGN_IN = 'sign-in-code';

    /**
     * Codes given in a signed-in session (settings).
     */
    public const IN_SETTINGS = 'second-factor';

    public function __construct(
        private AccountAttemptLimiter $limiter,
        private AuditLogger $audit,
    ) {}

    /**
     * Run a limited check. Returns whether the code was right.
     *
     * @param  self::AT_SIGN_IN|self::IN_SETTINGS  $where
     * @param  string  $field  The input the code came from, for the error.
     * @param  Closure(): bool  $check
     *
     * @throws ValidationException once too many wrong codes have been given.
     */
    public function attempt(User $user, string $where, string $field, Closure $check): bool
    {
        $result = $this->limiter->attempt($where, $user, $check);

        if ($result !== AttemptResult::Locked) {
            return $result === AttemptResult::Passed;
        }

        $this->audit->record('mfa.check.locked', AuditOutcome::Blocked, $user, [
            'step' => $where === self::AT_SIGN_IN ? 'sign_in' : 'settings',
        ]);

        // Someone who reached the code step at sign-in knows the password.
        // Tell the user once per lock.
        if ($where === self::AT_SIGN_IN && Cache::add('crms.sign-in-codes-locked.'.$user->getKey(), true, AccountAttemptLimiter::DECAY_SECONDS)) {
            $user->notify(new SignInCodesLocked);
        }

        throw ValidationException::withMessages([
            $field => $this->limiter->lockedMessage($where, $user, 'codes'),
        ]);
    }
}
