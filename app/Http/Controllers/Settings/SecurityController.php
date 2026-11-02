<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\User;
use App\Services\BrowserSessions;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The officer's own account security page: password, authenticator,
 * active sessions and recent sign-in activity.
 */
class SecurityController extends Controller
{
    /**
     * Audit events shown in the officer's "recent activity" list.
     *
     * @var list<string>
     */
    private const ACTIVITY_EVENTS = [
        'auth.login', 'auth.login.failed', 'auth.lockout', 'auth.login.blocked',
        'auth.logout', 'auth.session.expired', 'auth.password.changed',
        'auth.password_reset.completed', 'mfa.enrolment.confirmed', 'mfa.reset',
        'mfa.recovery_codes.generated', 'mfa.recovery_code.used', 'mfa.challenge.failed',
        'auth.sessions.revoked_others', 'auth.session.revoked',
    ];

    public function show(Request $request, BrowserSessions $sessions): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('settings/Security', [
            'account' => [
                'name' => $user->name,
                'email' => $user->email,
                'service_number' => $user->service_number,
                'rank' => $user->rank,
                'status' => $user->status->label(),
                'password_changed_at' => $user->password_changed_at?->toIso8601String(),
                'last_login_at' => $user->last_login_at?->toIso8601String(),
            ],
            'twoFactor' => [
                'enabled' => $user->hasEnabledTwoFactorAuthentication(),
                'confirmed_at' => $user->two_factor_confirmed_at?->toIso8601String(),
                'required' => (bool) config('crms.auth.require_mfa'),
                'recovery_codes_remaining' => $user->hasEnabledTwoFactorAuthentication() ? count($user->recoveryCodes()) : 0,
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
