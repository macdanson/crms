<?php

namespace App\Http\Controllers\Auth;

use App\Models\User;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Timebox;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\Http\Controllers\PasswordResetLinkController as FortifyPasswordResetLinkController;
use Laravel\Fortify\Http\Requests\SendPasswordResetLinkRequest;

/**
 * Fortify's "forgot password" endpoint, made to take the same time whether
 * or not the email address belongs to an account:
 *
 * - the reply is the same (PasswordResetLinkRequestedResponse);
 * - the email is queued (ResetPasswordLink);
 * - one timebox covers everything, including the hashing of a new token, and
 *   lasts longer than that hashing and the broker's own shorter timebox.
 *
 * It mirrors Fortify's store(), using Fortify's email field and broker, so
 * it can act on the broker's status.
 */
class PasswordResetLinkController extends FortifyPasswordResetLinkController
{
    /**
     * Minimum duration of a reset link request, in microseconds.
     */
    private const MINIMUM_DURATION = 500_000;

    public function store(SendPasswordResetLinkRequest $request): Responsable
    {
        return (new Timebox)->call(function () use ($request): Responsable {
            $email = User::normaliseEmail((string) $request->input(Fortify::email()));

            // The email rule allows a NUL byte, which the database refuses.
            // No account has one.
            $status = str_contains($email, "\0")
                ? Password::INVALID_USER
                : $this->broker()->sendResetLink([Fortify::email() => $email]);

            return $status === Password::RESET_LINK_SENT
                ? app(SuccessfulPasswordResetLinkRequestResponse::class, ['status' => $status])
                : app(FailedPasswordResetLinkRequestResponse::class, ['status' => $status]);
        }, self::MINIMUM_DURATION);
    }
}
