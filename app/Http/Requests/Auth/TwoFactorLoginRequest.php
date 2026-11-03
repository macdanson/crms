<?php

namespace App\Http\Requests\Auth;

use App\Enums\AuditOutcome;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\SecondFactor;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Http\Requests\TwoFactorLoginRequest as FortifyTwoFactorLoginRequest;

/**
 * Fortify's second sign-in step, which:
 *
 * - re-checks the account status: an account suspended after the password
 *   step must not finish signing in;
 * - uses a recovery code up atomically while checking it, so two sign-ins
 *   with the same code cannot both succeed, and the loser stays on the
 *   challenge with an error.
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

        if ($user instanceof User && ! $user->isActive()) {
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

        if (! app(SecondFactor::class)->consumeRecoveryCode($this->challengedUser(), $this->recovery_code)) {
            return null;
        }

        $this->session()->forget('login.id');

        return trim($this->recovery_code);
    }
}
