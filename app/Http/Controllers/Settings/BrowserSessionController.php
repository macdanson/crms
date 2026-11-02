<?php

namespace App\Http\Controllers\Settings;

use App\Enums\AuditOutcome;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\RevokeOtherSessionsRequest;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\BrowserSessions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Lets officers sign out sessions on other devices (developer guide §16).
 */
class BrowserSessionController extends Controller
{
    public function __construct(
        private BrowserSessions $sessions,
        private AuditLogger $audit,
    ) {}

    public function destroy(Request $request, string $session): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $revoked = $this->sessions->revoke($user, $session, $request->session()->getId());

        $this->audit->record(
            'auth.session.revoked',
            $revoked ? AuditOutcome::Success : AuditOutcome::Failure,
            $user,
        );

        return $revoked
            ? back()->with('status', 'That session has been signed out.')
            : back()->with('error', 'That session could not be found. It may already have ended.');
    }

    public function destroyOthers(RevokeOtherSessionsRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        // Rotates the stored password hash so other sessions fail the
        // AuthenticateSession check, then removes their stored session rows.
        Auth::logoutOtherDevices($request->string('password')->toString());

        $this->sessions->revokeOthers($user, $request->session()->getId());

        return back()->with('status', 'All other sessions have been signed out.');
    }
}
