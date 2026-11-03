<?php

namespace App\Http\Controllers\Auth;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Support\Timebox;
use Laravel\Fortify\Http\Controllers\PasswordResetLinkController as FortifyPasswordResetLinkController;
use Laravel\Fortify\Http\Requests\SendPasswordResetLinkRequest;

/**
 * Fortify's "forgot password" endpoint, made to take the same time whether
 * or not the email address belongs to an account. The reply is already the
 * same (PasswordResetLinkRequestedResponse) and the email is queued
 * (ResetPasswordLink); the timebox hides the remaining database work.
 */
class PasswordResetLinkController extends FortifyPasswordResetLinkController
{
    /**
     * Minimum duration of a reset link request, in microseconds.
     */
    private const MINIMUM_DURATION = 300_000;

    public function store(SendPasswordResetLinkRequest $request): Responsable
    {
        return (new Timebox)->call(fn (): Responsable => parent::store($request), self::MINIMUM_DURATION);
    }
}
