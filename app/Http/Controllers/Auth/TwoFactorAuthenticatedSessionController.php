<?php

namespace App\Http\Controllers\Auth;

use App\Http\Requests\Auth\TwoFactorLoginRequest;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
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
 * - one proof is checked per request, so a request costs at most one
 *   attempt: the authenticator code when one is sent, otherwise the
 *   recovery code (the same rule as SecondFactorRequest).
 */
class TwoFactorAuthenticatedSessionController extends FortifyTwoFactorAuthenticatedSessionController
{
    /**
     * @param  TwoFactorLoginRequest  $request
     */
    public function store(FortifyTwoFactorLoginRequest $request): Response|Responsable|RedirectResponse
    {
        $user = $request->challengedUser();

        $checkedField = $request->filled('code') ? 'code' : 'recovery_code';

        $passed = $checkedField === 'code'
            ? $request->hasValidCode()
            : $request->validRecoveryCode() !== null;

        if (! $passed) {
            event(new TwoFactorAuthenticationFailed($user));

            return $this->failed($request, $checkedField);
        }

        event(new ValidTwoFactorAuthenticationCodeProvided($user));

        $this->guard->login($user, $request->remember());

        $request->session()->regenerate();

        return app(TwoFactorLoginResponse::class);
    }

    /**
     * Fortify's failure response, but with the error on the code that was
     * actually checked (Fortify's picks the recovery code whenever one is sent).
     *
     * @param  'code'|'recovery_code'  $checkedField
     *
     * @throws ValidationException for a JSON request.
     */
    private function failed(FortifyTwoFactorLoginRequest $request, string $checkedField): RedirectResponse
    {
        $message = $checkedField === 'code'
            ? __('The provided two factor authentication code was invalid.')
            : __('The provided two factor recovery code was invalid.');

        if ($request->wantsJson()) {
            throw ValidationException::withMessages([$checkedField => [$message]]);
        }

        return redirect()->route('two-factor.login')->withErrors([$checkedField => $message]);
    }
}
