<?php

namespace App\Http\Controllers\Auth;

use App\Models\User;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Timebox;
use Laravel\Fortify\Http\Controllers\PasswordResetLinkController as FortifyPasswordResetLinkController;
use Laravel\Fortify\Http\Requests\SendPasswordResetLinkRequest;

/**
 * Fortify's "forgot password" endpoint, made to take the same time whether
 * or not the email address belongs to an account:
 *
 * - the reply is the same (PasswordResetLinkRequestedResponse);
 * - the email is queued (ResetPasswordLink);
 * - for an account, the broker hashes a new token, so an unknown email
 *   gets an equal hash of its own, whatever the hashing cost;
 * - the timebox hides the remaining database work.
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
            $email = User::normaliseEmail((string) $request->input('email'));

            if (! User::query()->where('email', $email)->exists()) {
                Hash::make(Str::random(64));
            }

            return parent::store($request);
        }, self::MINIMUM_DURATION);
    }
}
