<?php

namespace App\Listeners;

use App\Enums\AuditOutcome;
use App\Models\AuditEvent;
use App\Models\User;
use App\Notifications\NewDeviceSignIn;
use App\Services\AuditLogger;
use App\Services\BrowserSessions;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\OtherDeviceLogout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\PasswordResetLinkSent;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Fortify\Events\PasswordUpdatedViaController;
use Laravel\Fortify\Events\RecoveryCodeReplaced;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationChallenged;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Laravel\Fortify\Events\ValidTwoFactorAuthenticationCodeProvided;

/**
 * Turns framework authentication events into audit records (developer guide §12.1)
 * and performs the session bookkeeping that follows a successful sign-in.
 *
 * Each handle* method is registered automatically through event discovery.
 */
class RecordAuthenticationActivity
{
    /**
     * How far back a device counts as "recognised" for new-device alerts.
     */
    private const RECOGNISED_DEVICE_DAYS = 90;

    public function __construct(
        private AuditLogger $audit,
        private BrowserSessions $sessions,
    ) {}

    public function handleLogin(Login $event): void
    {
        $user = $event->user;

        if (! $user instanceof User) {
            return;
        }

        $request = request();

        if ($request->hasSession()) {
            $now = now()->getTimestamp();

            $request->session()->put([
                'crms.authenticated_at' => $now,
                'crms.last_activity_at' => $now,
            ]);

            // The user has just proven their password; avoid asking again
            // straight away (e.g. during mandatory authenticator enrolment).
            // A sign-in restored from a cookie proves nothing, so it does not count.
            if (! Auth::guard($event->guard)->viaRemember()) {
                $request->session()->passwordConfirmed();
            }
        }

        $this->notifyIfNewDevice($user, (string) $request->userAgent(), $request->ip());

        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->saveQuietly();

        $this->audit->record('auth.login', AuditOutcome::Success, $user, [
            'mfa' => $user->hasEnabledTwoFactorAuthentication(),
        ]);
    }

    public function handleFailed(Failed $event): void
    {
        $email = Str::lower(trim((string) ($event->credentials['email'] ?? '')));

        $user = $event->user instanceof User
            ? $event->user
            : User::query()->where('email', $email)->first();

        $this->audit->record('auth.login.failed', AuditOutcome::Failure, $user, [
            'email' => Str::limit($email, 255, ''),
        ]);
    }

    public function handleLockout(Lockout $event): void
    {
        $email = Str::lower(trim((string) $event->request->input('email')));

        $this->audit->record('auth.lockout', AuditOutcome::Blocked, User::query()->where('email', $email)->first(), [
            'email' => Str::limit($email, 255, ''),
        ]);
    }

    public function handleLogout(Logout $event): void
    {
        $this->audit->record('auth.logout', AuditOutcome::Success, $event->user instanceof User ? $event->user : null);
    }

    public function handleOtherDeviceLogout(OtherDeviceLogout $event): void
    {
        $this->audit->record('auth.sessions.revoked_others', AuditOutcome::Success, $event->user instanceof User ? $event->user : null);
    }

    public function handlePasswordResetLinkSent(PasswordResetLinkSent $event): void
    {
        $this->audit->record('auth.password_reset.requested', AuditOutcome::Success, $event->user instanceof User ? $event->user : null);
    }

    public function handlePasswordReset(PasswordReset $event): void
    {
        $this->audit->record('auth.password_reset.completed', AuditOutcome::Success, $event->user instanceof User ? $event->user : null);
    }

    public function handlePasswordUpdated(PasswordUpdatedViaController $event): void
    {
        $user = $event->user;

        $revoked = request()->hasSession()
            ? $this->sessions->revokeOthers($user, request()->session()->getId())
            : 0;

        $this->audit->record('auth.password.changed', AuditOutcome::Success, $user, [
            'sessions_revoked' => $revoked,
        ]);
    }

    public function handleTwoFactorEnabled(TwoFactorAuthenticationEnabled $event): void
    {
        $this->audit->record('mfa.enrolment.started', AuditOutcome::Success, $this->asUser($event->user));
    }

    public function handleTwoFactorConfirmed(TwoFactorAuthenticationConfirmed $event): void
    {
        $this->audit->record('mfa.enrolment.confirmed', AuditOutcome::Success, $this->asUser($event->user));
    }

    public function handleTwoFactorDisabled(TwoFactorAuthenticationDisabled $event): void
    {
        $this->audit->record('mfa.disabled', AuditOutcome::Success, $this->asUser($event->user));
    }

    public function handleRecoveryCodesGenerated(RecoveryCodesGenerated $event): void
    {
        $this->audit->record('mfa.recovery_codes.generated', AuditOutcome::Success, $this->asUser($event->user));
    }

    public function handleRecoveryCodeReplaced(RecoveryCodeReplaced $event): void
    {
        $this->audit->record('mfa.recovery_code.used', AuditOutcome::Success, $this->asUser($event->user));
    }

    public function handleTwoFactorChallenged(TwoFactorAuthenticationChallenged $event): void
    {
        $this->audit->record('mfa.challenge.issued', AuditOutcome::Success, $this->asUser($event->user));
    }

    public function handleTwoFactorFailed(TwoFactorAuthenticationFailed $event): void
    {
        $this->audit->record('mfa.challenge.failed', AuditOutcome::Failure, $this->asUser($event->user));
    }

    public function handleTwoFactorPassed(ValidTwoFactorAuthenticationCodeProvided $event): void
    {
        $this->audit->record('mfa.challenge.passed', AuditOutcome::Success, $this->asUser($event->user));
    }

    /**
     * Alert the user when a sign-in comes from a browser that has not been
     * used successfully on their account recently. The very first sign-in
     * after activation is not alerted.
     */
    private function notifyIfNewDevice(User $user, string $userAgent, ?string $ipAddress): void
    {
        if (! config('crms.auth.notify_new_device')) {
            return;
        }

        $previousLogins = AuditEvent::query()
            ->where('user_id', $user->getKey())
            ->where('event', 'auth.login')
            ->where('occurred_at', '>=', now()->subDays(self::RECOGNISED_DEVICE_DAYS));

        if (! (clone $previousLogins)->exists()) {
            return;
        }

        if ($previousLogins->where('user_agent', Str::limit($userAgent, 509))->exists()) {
            return;
        }

        $agent = $this->sessions->describeAgent($userAgent);

        $user->notify(new NewDeviceSignIn(
            device: "{$agent['browser']} on {$agent['platform']}",
            ipAddress: $ipAddress,
            signedInAt: now(),
        ));
    }

    private function asUser(mixed $user): ?User
    {
        return $user instanceof User ? $user : null;
    }
}
