<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;

/**
 * Returns an identical answer whether or not the email address belongs to an
 * account, so the reset form cannot be used to discover officer emails.
 */
class PasswordResetLinkRequestedResponse implements FailedPasswordResetLinkRequestResponse, SuccessfulPasswordResetLinkRequestResponse
{
    public const MESSAGE = 'If that email address belongs to a CRMS account, a password reset link is on its way. The link expires in 60 minutes.';

    /**
     * Fortify passes the broker status, which is intentionally ignored.
     */
    public function __construct(public string $status = '') {}

    /**
     * @param  Request  $request
     */
    public function toResponse($request): JsonResponse|RedirectResponse
    {
        if ($request->wantsJson()) {
            return new JsonResponse(['message' => self::MESSAGE], 200);
        }

        return back()->with('status', self::MESSAGE);
    }
}
