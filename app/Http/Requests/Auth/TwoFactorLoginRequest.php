<?php

namespace App\Http\Requests\Auth;

use App\Enums\AuditOutcome;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Http\Requests\TwoFactorLoginRequest as FortifyTwoFactorLoginRequest;

/**
 * Fortify's second sign-in step, which re-checks the account status: an
 * account suspended after the password step must not finish signing in.
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
}
