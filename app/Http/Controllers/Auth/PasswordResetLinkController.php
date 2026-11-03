<?php

namespace App\Http\Controllers\Auth;

use App\Models\User;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
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
 * - sending a link hashes a new token, so every other outcome (unknown
 *   email, or a link requested again too soon) hashes a stand-in;
 * - the timebox hides the remaining database work.
 *
 * It mirrors Fortify's store(), using Fortify's email field and broker, so
 * it can act on the broker's status.
 */
class PasswordResetLinkController extends FortifyPasswordResetLinkController
{
    /**
     * Minimum duration of a reset link request, in microseconds.
     */
    private const MINIMUM_DURATION = 300_000;

    public function store(SendPasswordResetLinkRequest $request): Responsable
    {
        return (new Timebox)->call(function () use ($request): Responsable {
            $status = $this->broker()->sendResetLink([
                Fortify::email() => User::normaliseEmail((string) $request->input(Fortify::email())),
            ]);

            if ($status !== Password::RESET_LINK_SENT) {
                Hash::make(Str::random(64));

                return app(FailedPasswordResetLinkRequestResponse::class, ['status' => $status]);
            }

            return app(SuccessfulPasswordResetLinkRequestResponse::class, ['status' => $status]);
        }, self::MINIMUM_DURATION);
    }
}
