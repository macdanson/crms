<?php

namespace App\Http\Controllers\Settings;

use App\Enums\AuditOutcome;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\RevokeOtherSessionsRequest;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\BrowserSessions;
use App\Services\SessionPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Lets users sign out sessions on other devices (developer guide §16).
 */
class BrowserSessionController extends Controller
{
    public function __construct(
        private BrowserSessions $sessions,
        private AuditLogger $audit,
        private SessionPolicy $policy,
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

        // The password was checked by RevokeOtherSessionsRequest.
        $revoked = $this->policy->endOtherSessions($user, $request->session());

        $this->audit->record('auth.sessions.revoked_others', AuditOutcome::Success, $user, [
            'sessions_revoked' => $revoked,
        ]);

        return back()->with('status', 'All other sessions have been signed out.');
    }
}
