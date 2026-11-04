<?php

namespace App\Http\Requests\Auth;

use App\Enums\AuditOutcome;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\SecondFactor;
use App\Services\SecondFactorCheck;
use App\Services\SessionPolicy;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Http\Requests\TwoFactorLoginRequest as FortifyTwoFactorLoginRequest;

/**
 * Fortify's second sign-in step, which:
 *
 * - re-checks the account status: an account suspended after the password
 *   step must not finish signing in;
 * - ends the challenge once it is older than the challenge timeout, or once
 *   the password has been changed or reset since it was given;
 * - uses a recovery code up atomically while checking it, so two sign-ins
 *   with the same code cannot both succeed, and the loser stays on the
 *   challenge with an error;
 * - limits code guesses per account (SecondFactorCheck), not only per
 *   challenge, so restarting the challenge does not help.
 */
class TwoFactorLoginRequest extends FortifyTwoFactorLoginRequest
{
    /**
     * Shown when a challenge has expired or the password changed meanwhile.
     */
    private const CHALLENGE_ENDED = 'Your sign-in took too long or your password has changed. Please sign in again.';

    /**
     * Get the user that is attempting the two-factor challenge.
     *
     * @throws HttpResponseException when no challenge is in progress (Fortify).
     * @throws ValidationException when the account or the challenge may not continue.
     */
    public function challengedUser(): mixed
    {
        $user = parent::challengedUser();

        if (! $user instanceof User) {
            return $user;
        }

        $policy = app(SessionPolicy::class);

        if (! $user->isActive()) {
            $policy->endChallenge($this->session());

            app(AuditLogger::class)->record('auth.login.blocked', AuditOutcome::Blocked, $user, [
                'status' => $user->status->value,
                'step' => 'two_factor',
            ]);

            throw ValidationException::withMessages([
                'email' => $user->status->signInBlockedMessage(),
            ])->redirectTo(route('login'));
        }

        if (! $policy->challengeIsCurrent($this->session(), $user)) {
            $policy->endChallenge($this->session());

            app(AuditLogger::class)->record('auth.challenge.expired', AuditOutcome::Blocked, $user);

            throw ValidationException::withMessages([
                'email' => self::CHALLENGE_ENDED,
            ])->redirectTo(route('login'));
        }

        return $user;
    }

    /**
     * Whether a challenge is in progress that may still be completed. An
     * expired one is not offered at all.
     */
    public function hasChallengedUser(): bool
    {
        if (! $this->session()->has('login.id')) {
            return false;
        }

        // The same checks (and audit) as challengedUser(), which ends a
        // challenge that may not continue. The reason is shown on the
        // sign-in page the user is sent to.
        try {
            $this->challengedUser();
        } catch (HttpResponseException) {
            return false;
        } catch (ValidationException $exception) {
            $this->session()->flash('error', collect($exception->errors())->flatten()->first());

            return false;
        }

        return true;
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

        if (! $this->limitedCheck($user, 'recovery_code', fn (): bool => app(SecondFactor::class)->consumeRecoveryCode($user, $recoveryCode))) {
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

        if (! $this->limitedCheck($user, 'code', fn (): bool => app(SecondFactor::class)->isValidCode($user, $code))) {
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
    private function limitedCheck(User $user, string $field, Closure $check): bool
    {
        return app(SecondFactorCheck::class)->attempt($user, SecondFactorCheck::AT_SIGN_IN, $field, $check);
    }
}
