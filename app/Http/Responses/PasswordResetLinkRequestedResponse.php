<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;

/**
 * Returns an identical answer whether or not the email address belongs to an
 * account, so the reset form cannot be used to discover user emails.
 */
class PasswordResetLinkRequestedResponse implements FailedPasswordResetLinkRequestResponse, SuccessfulPasswordResetLinkRequestResponse
{
    /**
     * Fortify passes the broker status, which is intentionally ignored.
     */
    public function __construct(public string $status = '') {}

    /**
     * The same message whatever the outcome. The expiry comes from the reset
     * broker, so the message always matches how long the link works.
     */
    public static function message(): string
    {
        $minutes = (int) config('auth.passwords.users.expire');

        return "If that email address belongs to a CRMS account, a password reset link is on its way. The link expires in {$minutes} minutes.";
    }

    /**
     * @param  Request  $request
     */
    public function toResponse($request): JsonResponse|RedirectResponse
    {
        if ($request->wantsJson()) {
            return new JsonResponse(['message' => self::message()], 200);
        }

        return back()->with('status', self::message());
    }
}
