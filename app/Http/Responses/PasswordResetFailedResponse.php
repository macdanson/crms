<?php

namespace App\Http\Responses;

use App\Enums\AuditOutcome;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\FailedPasswordResetResponse;
use Laravel\Fortify\Fortify;

/**
 * The same answer for a wrong or expired link whether or not the email
 * address belongs to an account, so the reset form cannot be used to discover
 * user emails. The failure is audited.
 */
class PasswordResetFailedResponse implements FailedPasswordResetResponse
{
    /**
     * Fortify passes the broker status, which is intentionally ignored.
     */
    public function __construct(private AuditLogger $audit, public string $status = '') {}

    /**
     * @param  Request  $request
     *
     * @throws ValidationException for a JSON request.
     */
    public function toResponse($request): RedirectResponse
    {
        $this->audit->record(
            'auth.password_reset.failed',
            AuditOutcome::Failure,
            User::findByEmail($request->string(Fortify::email())->toString()),
        );

        $message = __('This password reset link is invalid or has expired. Request a new one.');

        if ($request->wantsJson()) {
            throw ValidationException::withMessages([Fortify::email() => [$message]]);
        }

        return back()
            ->withInput($request->only(Fortify::email()))
            ->withErrors([Fortify::email() => $message]);
    }
}
