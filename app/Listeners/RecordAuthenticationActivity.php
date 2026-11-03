<?php

namespace App\Listeners;

use App\Enums\AuditOutcome;
use App\Models\AuditEvent;
use App\Models\User;
use App\Notifications\NewDeviceSignIn;
use App\Services\AuditLogger;
use App\Services\BrowserSessions;
use App\Services\KeyedHash;
use App\Services\SessionPolicy;
use App\Services\SessionTermination;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\PasswordResetLinkSent;
use Illuminate\Support\Facades\Auth;
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

    /**
     * Session key holding the sign-in before the current one.
     */
    public const PREVIOUS_LOGIN = 'crms.previous_login';

    public function __construct(
        private AuditLogger $audit,
        private BrowserSessions $sessions,
        private SessionPolicy $policy,
        private KeyedHash $keyedHash,
    ) {}

    public function handleLogin(Login $event): void
    {
        $user = $event->user;

        if (! $user instanceof User) {
            return;
        }

        $request = request();

        if ($request->hasSession()) {
            $this->policy->start($request->session(), $user);

            $request->session()->put([
                // Read before last_login_* is overwritten below, so pages can
                // show the sign-in before this one, not this one.
                self::PREVIOUS_LOGIN => [
                    'at' => $user->last_login_at?->toIso8601String(),
                    'ip' => $user->last_login_ip,
                ],
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

        // The sign-in itself is audited by SignedInResponse, once Fortify has
        // issued the session ID the user goes on to use.
    }

    public function handleFailed(Failed $event): void
    {
        $email = User::normaliseEmail((string) ($event->credentials['email'] ?? ''));

        $user = $event->user instanceof User
            ? $event->user
            : User::query()->where('email', $email)->first();

        $this->audit->record('auth.login.failed', AuditOutcome::Failure, $user, $this->attemptedEmail($email));
    }

    public function handleLockout(Lockout $event): void
    {
        $email = User::normaliseEmail((string) $event->request->input('email'));

        $this->audit->record('auth.lockout', AuditOutcome::Blocked, User::query()->where('email', $email)->first(), $this->attemptedEmail($email));
    }

    public function handleLogout(Logout $event): void
    {
        if (SessionTermination::isTerminating(request())) {
            return;
        }

        $this->audit->record('auth.logout', AuditOutcome::Success, $event->user instanceof User ? $event->user : null);
    }

    public function handlePasswordResetLinkSent(PasswordResetLinkSent $event): void
    {
        $this->audit->record('auth.password_reset.requested', AuditOutcome::Success, $event->user instanceof User ? $event->user : null);
    }

    public function handlePasswordReset(PasswordReset $event): void
    {
        $this->audit->record('auth.password_reset.completed', AuditOutcome::Success, $event->user instanceof User ? $event->user : null);
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
        $user = $this->asUser($event->user);

        if ($user !== null && request()->hasSession()) {
            $this->policy->startChallenge(request()->session(), $user);
        }

        $this->audit->record('mfa.challenge.issued', AuditOutcome::Success, $user);
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
     * used successfully on their account recently. Only the very first
     * sign-in to the account is not alerted; an account unused for a long
     * time is alerted whatever the device.
     */
    private function notifyIfNewDevice(User $user, string $userAgent, ?string $ipAddress): void
    {
        if (! config('crms.auth.notify_new_device')) {
            return;
        }

        // Read before last_login_at is updated for this sign-in.
        if ($user->last_login_at === null) {
            return;
        }

        // Browsers change their user agent on every update, so compare the
        // browser and platform rather than the exact string.
        $agent = $this->sessions->describeAgent($userAgent);

        $recognised = AuditEvent::query()
            ->where('user_id', $user->getKey())
            ->where('event', 'auth.login')
            ->where('occurred_at', '>=', now()->subDays(self::RECOGNISED_DEVICE_DAYS))
            ->whereNotNull('user_agent')
            ->distinct()
            ->limit(200)
            ->pluck('user_agent')
            ->contains(function (string $previousAgent) use ($agent): bool {
                $previous = $this->sessions->describeAgent($previousAgent);

                return $previous['browser'] === $agent['browser'] && $previous['platform'] === $agent['platform'];
            });

        if ($recognised) {
            return;
        }

        $user->notify(new NewDeviceSignIn(
            device: "{$agent['browser']} on {$agent['platform']}",
            ipAddress: $ipAddress,
            signedInAt: now(),
        ));
    }

    /**
     * What was typed in the email field of a failed sign-in. Only a valid
     * email address is kept as typed: anything else may be a password typed
     * in the wrong field, so only a keyed hash is kept, which still links
     * repeated attempts.
     *
     * @return array{email: string}|array{email_hash: string}
     */
    private function attemptedEmail(string $email): array
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) !== false && mb_strlen($email) <= 255) {
            return ['email' => $email];
        }

        return ['email_hash' => $this->keyedHash->of($email)];
    }

    private function asUser(mixed $user): ?User
    {
        return $user instanceof User ? $user : null;
    }
}
