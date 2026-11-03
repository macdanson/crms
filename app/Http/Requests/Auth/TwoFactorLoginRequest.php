<?php

namespace App\Http\Requests\Auth;

use App\Enums\AttemptResult;
use App\Enums\AuditOutcome;
use App\Models\User;
use App\Services\AccountAttemptLimiter;
use App\Services\AuditLogger;
use App\Services\SecondFactor;
use Closure;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Http\Requests\TwoFactorLoginRequest as FortifyTwoFactorLoginRequest;

/**
 * Fortify's second sign-in step, which:
 *
 * - re-checks the account status: an account suspended after the password
 *   step must not finish signing in;
 * - uses a recovery code up atomically while checking it, so two sign-ins
 *   with the same code cannot both succeed, and the loser stays on the
 *   challenge with an error;
 * - limits code guesses per account (shared with the checks in settings),
 *   not only per challenge, so restarting the challenge does not help.
 */
class TwoFactorLoginRequest extends FortifyTwoFactorLoginRequest
{
    /**
     * Get the user that is attempting the two-factor challenge.
     *
     * @throws ValidationException
     */
    public function challengedUser(): mixed
    {
        $user = parent::challengedUser();

        if (! $user instanceof User) {
            return $user;
        }

        if (! $user->isActive()) {
            $this->session()->forget(['login.id', 'login.remember']);

            app(AuditLogger::class)->record('auth.login.blocked', AuditOutcome::Blocked, $user, [
                'status' => $user->status->value,
                'step' => 'two_factor',
            ]);

            throw ValidationException::withMessages([
                'email' => $user->status->signInBlockedMessage(),
            ])->redirectTo(route('login'));
        }

        return $user;
    }

    /**
     * The recovery code given, once it has been used up, or null when it is
     * not (or no longer) one of the user's. Fortify drops the challenge only
     * when a code is returned.
     */
    public function validRecoveryCode(): ?string
    {
        if (! is_string($this->recovery_code) || $this->recovery_code === '') {
            return null;
        }

        $user = $this->challengedUser();
        $recoveryCode = $this->recovery_code;

        if (! $this->limitedCheck($user, fn (): bool => app(SecondFactor::class)->consumeRecoveryCode($user, $recoveryCode))) {
            return null;
        }

        $this->session()->forget('login.id');

        return trim($recoveryCode);
    }

    /**
     * Whether the authenticator code is right (per-account limited).
     */
    public function hasValidCode(): bool
    {
        if (! is_string($this->code) || $this->code === '') {
            return false;
        }

        $user = $this->challengedUser();
        $code = $this->code;

        if (! $this->limitedCheck($user, fn (): bool => app(SecondFactor::class)->isValidCode($user, $code))) {
            return false;
        }

        $this->session()->forget('login.id');

        return true;
    }

    /**
     * @param  Closure(): bool  $check
     *
     * @throws ValidationException once too many wrong codes have been given.
     */
    private function limitedCheck(User $user, Closure $check): bool
    {
        $limiter = app(AccountAttemptLimiter::class);
        $result = $limiter->attempt('second-factor', $user, $check);

        if ($result === AttemptResult::Locked) {
            app(AuditLogger::class)->record('mfa.check.locked', AuditOutcome::Blocked, $user, ['step' => 'sign_in']);

            throw ValidationException::withMessages([
                ($this->filled('recovery_code') ? 'recovery_code' : 'code') => $limiter->lockedMessage('second-factor', $user, 'codes'),
            ]);
        }

        return $result === AttemptResult::Passed;
    }
}
