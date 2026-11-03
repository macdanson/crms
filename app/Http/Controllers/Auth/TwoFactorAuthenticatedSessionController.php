<?php

namespace App\Http\Controllers\Auth;

use App\Http\Requests\Auth\TwoFactorLoginRequest;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\RedirectResponse;
use Laravel\Fortify\Contracts\FailedTwoFactorLoginResponse;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Laravel\Fortify\Events\ValidTwoFactorAuthenticationCodeProvided;
use Laravel\Fortify\Http\Controllers\TwoFactorAuthenticatedSessionController as FortifyTwoFactorAuthenticatedSessionController;
use Laravel\Fortify\Http\Requests\TwoFactorLoginRequest as FortifyTwoFactorLoginRequest;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fortify's sign-in challenge, except that:
 *
 * - a recovery code is used up once, atomically, while it is checked
 *   (TwoFactorLoginRequest::validRecoveryCode); Fortify's own store() would
 *   replace it a second time afterwards;
 * - an authenticator code is checked first, so a recovery code sent with a
 *   correct authenticator code is not used up.
 */
class TwoFactorAuthenticatedSessionController extends FortifyTwoFactorAuthenticatedSessionController
{
    /**
     * @param  TwoFactorLoginRequest  $request
     */
    public function store(FortifyTwoFactorLoginRequest $request): Response|Responsable|RedirectResponse
    {
        $user = $request->challengedUser();

        $passed = $request->hasValidCode()
            || ($request->filled('recovery_code') && $request->validRecoveryCode() !== null);

        if (! $passed) {
            event(new TwoFactorAuthenticationFailed($user));

            return app(FailedTwoFactorLoginResponse::class)->toResponse($request);
        }

        event(new ValidTwoFactorAuthenticationCodeProvided($user));

        $this->guard->login($user, $request->remember());

        $request->session()->regenerate();

        return app(TwoFactorLoginResponse::class);
    }
}
