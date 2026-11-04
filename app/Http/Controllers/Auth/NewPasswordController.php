<?php

namespace App\Http\Controllers\Auth;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Timebox;
use Laravel\Fortify\Contracts\FailedPasswordResetResponse;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\Http\Controllers\NewPasswordController as FortifyNewPasswordController;

/**
 * Fortify's "reset password" endpoint, made to take the same time whether or
 * not the email address belongs to an account: only for an account does the
 * broker hash the token it was given.
 */
class NewPasswordController extends FortifyNewPasswordController
{
    /**
     * Minimum duration of a reset attempt, in microseconds. Above what the
     * token check costs at any reasonable hashing cost.
     */
    private const MINIMUM_DURATION = 500_000;

    public function store(Request $request): Responsable
    {
        return (new Timebox)->call(function () use ($request): Responsable {
            $email = $request->input(Fortify::email());

            // The email rule allows a NUL byte, which the database refuses.
            // No account has one.
            return is_string($email) && str_contains($email, "\0")
                ? app(FailedPasswordResetResponse::class, ['status' => Password::INVALID_USER])
                : parent::store($request);
        }, self::MINIMUM_DURATION);
    }
}
