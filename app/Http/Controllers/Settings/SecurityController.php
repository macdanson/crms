<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Listeners\RecordAuthenticationActivity;
use App\Models\AuditEvent;
use App\Models\User;
use App\Services\BrowserSessions;
use App\Services\SecondFactor;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The user's own account security page: password, authenticator,
 * active sessions and recent sign-in activity.
 */
class SecurityController extends Controller
{
    /**
     * Audit events shown in the user's "recent activity" list.
     *
     * @var list<string>
     */
    private const ACTIVITY_EVENTS = [
        'auth.login', 'auth.login.failed', 'auth.lockout', 'auth.login.blocked',
        'auth.logout', 'auth.session.expired', 'auth.password.changed',
        'auth.password_reset.completed', 'mfa.enrolment.confirmed', 'mfa.reset',
        'mfa.recovery_codes.generated', 'mfa.recovery_code.used', 'mfa.challenge.failed',
        'auth.sessions.revoked_others', 'auth.session.revoked', 'auth.session.invalidated', 'mfa.reset.failed',
        'mfa.step_up.failed', 'auth.password_check.failed', 'auth.password_check.locked', 'mfa.disabled',
    ];

    public function show(Request $request, BrowserSessions $sessions, SecondFactor $secondFactor): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('settings/Security', [
            'account' => [
                'name' => $user->name,
                'email' => $user->email,
                'staff_number' => $user->staff_number,
                'job_title' => $user->job_title,
                'status' => $user->status->label(),
                'password_changed_at' => $user->password_changed_at?->toIso8601String(),
                'previous_login' => $request->session()->get(RecordAuthenticationActivity::PREVIOUS_LOGIN),
            ],
            'twoFactor' => [
                'enabled' => $user->hasEnabledTwoFactorAuthentication(),
                'confirmed_at' => $user->two_factor_confirmed_at?->toIso8601String(),
                'required' => (bool) config('crms.auth.require_mfa'),
                'recovery_codes_remaining' => $user->remainingRecoveryCodeCount(),
                'second_factor_confirmed' => $secondFactor->isRecentlyConfirmed($request->session()),
            ],
            'sessionsSupported' => $sessions->supported(),
            'sessions' => $sessions->forUser($user, $request->session()->getId())->values(),
            'activity' => $user->auditEvents()
                ->whereIn('event', self::ACTIVITY_EVENTS)
                ->latest('occurred_at')
                ->limit(12)
                ->get()
                ->map(function (AuditEvent $event) use ($sessions): array {
                    $agent = $sessions->describeAgent((string) $event->user_agent);

                    return [
                        'id' => $event->id,
                        'event' => $event->event,
                        'outcome' => $event->outcome->value,
                        'ip_address' => $event->ip_address,
                        'device' => "{$agent['browser']} on {$agent['platform']}",
                        'occurred_at' => $event->occurred_at->toIso8601String(),
                    ];
                }),
        ]);
    }
}
