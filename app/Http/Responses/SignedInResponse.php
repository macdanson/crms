<?php

namespace App\Http\Responses;

use App\Enums\AuditOutcome;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\LoginResponse;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse;
use Laravel\Fortify\Fortify;

/**
 * Sent once sign-in is complete (password, or password and authenticator).
 *
 * The sign-in is audited here rather than on the Login event: Fortify issues
 * a new session ID after that event, and recording it here ties the audit
 * entry to the session the user goes on to use.
 */
class SignedInResponse implements LoginResponse, TwoFactorLoginResponse
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * @param  Request  $request
     */
    public function toResponse($request): JsonResponse|RedirectResponse
    {
        $user = $request->user();

        if ($user instanceof User) {
            $this->audit->record('auth.login', AuditOutcome::Success, $user, [
                'mfa' => $user->hasEnabledTwoFactorAuthentication(),
            ]);
        }

        if ($request->wantsJson()) {
            return $request->routeIs('two-factor.login.store')
                ? new JsonResponse('', 204)
                : new JsonResponse(['two_factor' => false]);
        }

        return redirect()->intended(Fortify::redirects('login'));
    }
}
