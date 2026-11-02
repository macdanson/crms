<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\AuditOutcome;
use App\Models\User;
use App\Notifications\AccountInvitation;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;

/**
 * Provisioning and lifecycle operations for user accounts (developer guide §6, §27.2).
 *
 * Every state change is audited and, where access is withdrawn, the user's
 * live sessions are revoked immediately.
 */
class AccountLifecycle
{
    public function __construct(
        private AuditLogger $audit,
        private BrowserSessions $sessions,
    ) {}

    /**
     * Provision a pending account (or refresh an existing pending one) and email
     * the user a single-use activation link.
     *
     * @param  array{name: string, email: string, staff_number?: string|null, job_title?: string|null}  $attributes
     */
    public function invite(array $attributes, ?User $invitedBy = null): User
    {
        $email = Str::lower(trim($attributes['email']));

        $user = User::query()->where('email', $email)->first();

        if ($user !== null && ! $user->isPending()) {
            throw ValidationException::withMessages([
                'email' => 'An account with this email address has already been activated.',
            ]);
        }

        $user ??= new User;
        $user->forceFill([
            'name' => $attributes['name'],
            'email' => $email,
            'staff_number' => $attributes['staff_number'] ?? $user->staff_number,
            'job_title' => $attributes['job_title'] ?? $user->job_title,
            'status' => AccountStatus::Pending,
            // An unusable random secret until the user chooses a password.
            'password' => Str::password(64),
        ])->save();

        $user->notify(new AccountInvitation($this->invitationBroker()->createToken($user)));

        $this->audit->record('account.invited', AuditOutcome::Success, $user, [
            'invited_by' => $invitedBy?->getKey(),
        ]);

        return $user;
    }

    /**
     * Complete activation: set the chosen password, prove email ownership and
     * activate the account. Returns false when the link is invalid or expired.
     */
    public function activate(string $email, string $token, string $password): bool
    {
        $user = User::query()
            ->where('email', Str::lower(trim($email)))
            ->where('status', AccountStatus::Pending)
            ->first();

        $broker = $this->invitationBroker();

        if ($user === null || ! $broker->tokenExists($user, $token)) {
            $this->audit->record('account.activation.failed', AuditOutcome::Failure, $user);

            return false;
        }

        DB::transaction(function () use ($user, $password, $broker): void {
            $user->forceFill([
                'password' => $password,
                'password_changed_at' => now(),
                'email_verified_at' => $user->email_verified_at ?? now(),
                'status' => AccountStatus::Active,
                'remember_token' => Str::random(60),
            ])->save();

            $broker->deleteToken($user);
        });

        $this->audit->record('account.activated', AuditOutcome::Success, $user);

        return true;
    }

    public function suspend(User $user, string $reason, ?User $actor = null): void
    {
        $user->forceFill([
            'status' => AccountStatus::Suspended,
            'suspended_at' => now(),
            'suspension_reason' => $reason,
            'remember_token' => Str::random(60),
        ])->save();

        $revoked = $this->sessions->revokeAll($user);

        $this->audit->record('account.suspended', AuditOutcome::Success, $user, [
            'actor_id' => $actor?->getKey(),
            'reason' => $reason,
            'sessions_revoked' => $revoked,
        ]);
    }

    public function reactivate(User $user, ?User $actor = null): void
    {
        $user->forceFill([
            'status' => AccountStatus::Active,
            'suspended_at' => null,
            'suspension_reason' => null,
        ])->save();

        $this->audit->record('account.reactivated', AuditOutcome::Success, $user, [
            'actor_id' => $actor?->getKey(),
        ]);
    }

    /**
     * Clear the user's authenticator so they must enrol again at next
     * sign-in (e.g. lost phone). Existing sessions are revoked.
     */
    public function resetTwoFactor(User $user, ?User $actor = null): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            // Invalidate any "remember me" cookie, which would otherwise let
            // its holder sign back in and enrol their own authenticator.
            'remember_token' => Str::random(60),
        ])->save();

        TwoFactorAuthenticationDisabled::dispatch($user);

        // Users replacing their own authenticator keep their current session;
        // a reset by anyone else signs the user out everywhere.
        $revoked = $actor?->is($user) ? 0 : $this->sessions->revokeAll($user);

        $this->audit->record('mfa.reset', AuditOutcome::Success, $user, [
            'actor_id' => $actor?->getKey(),
            'sessions_revoked' => $revoked,
        ]);
    }

    private function invitationBroker(): PasswordBroker
    {
        /** @var PasswordBroker */
        return Password::broker('invitations');
    }
}
