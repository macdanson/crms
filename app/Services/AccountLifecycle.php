<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\AuditOutcome;
use App\Models\User;
use App\Notifications\AccountInvitation;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Support\Timebox;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Provisioning and lifecycle operations for user accounts (developer guide §6, §27.2).
 *
 * Every state change is audited and, where access is withdrawn, the user's
 * live sessions are revoked immediately.
 */
class AccountLifecycle
{
    /**
     * Minimum duration of a failed activation, in microseconds.
     */
    private const MINIMUM_ACTIVATION_DURATION = 500_000;

    public function __construct(
        private AuditLogger $audit,
        private SessionPolicy $policy,
    ) {}

    /**
     * Provision a pending account (or refresh an existing pending one) and email
     * the user a single-use activation link.
     *
     * @param  array{name: string, email: string, staff_number?: string|null, job_title?: string|null}  $attributes
     */
    public function invite(array $attributes, ?User $invitedBy = null): User
    {
        $email = User::normaliseEmail($attributes['email']);

        $user = User::query()->where('email', $email)->first();

        if ($user !== null && ! $user->isPending()) {
            throw ValidationException::withMessages([
                'email' => 'An account with this email address has already been activated.',
            ]);
        }

        $details = [
            'name' => $attributes['name'],
            'email' => $email,
            'staff_number' => $attributes['staff_number'] ?? $user?->staff_number,
            'job_title' => $attributes['job_title'] ?? $user?->job_title,
            'status' => AccountStatus::Pending,
            // An unusable random secret until the user chooses a password. A
            // new one for every invitation also marks the invitation (see
            // claimPendingAccount).
            'password' => Hash::make(Str::password(64)),
        ];

        [$user, $token] = DB::transaction(fn (): array => $user === null
            ? $this->createPendingAccount($details)
            : [$user, $this->reinvitePendingAccount($user, $details)]);

        // The account exists from here on, so it is on record even if the
        // email cannot be sent.
        $this->audit->record('account.invited', AuditOutcome::Success, $user, [
            'invited_by' => $invitedBy?->getKey(),
        ]);

        try {
            $user->notify(new AccountInvitation($token));
        } catch (Throwable $exception) {
            $this->audit->record('account.invitation.failed', AuditOutcome::Failure, $user);

            throw $exception;
        }

        return $user;
    }

    /**
     * Complete activation: set the chosen password, prove email ownership and
     * activate the account. Returns false when the link is invalid or expired.
     *
     * A failure takes the same time whether or not the email address has an
     * invitation: only then would the token be hashed and checked.
     */
    public function activate(string $email, #[\SensitiveParameter] string $token, #[\SensitiveParameter] string $password): bool
    {
        return (new Timebox)->call(function (Timebox $timebox) use ($email, $token, $password): bool {
            $user = User::findByEmail($email);
            $user = $user?->isPending() ? $user : null;

            $broker = $this->invitationBroker();

            $activated = $user !== null
                && $broker->tokenExists($user, $token)
                && DB::transaction(function () use ($user, $password, $broker): bool {
                    if (! $this->claimPendingAccount($user, $password)) {
                        return false;
                    }

                    $broker->deleteToken($user);

                    return true;
                });

            if (! $activated) {
                $this->audit->record('account.activation.failed', AuditOutcome::Failure, $user);

                return false;
            }

            $this->audit->record('account.activated', AuditOutcome::Success, $user);

            $timebox->returnEarly();

            return true;
        }, self::MINIMUM_ACTIVATION_DURATION);
    }

    public function suspend(User $user, string $reason, ?User $actor = null): void
    {
        // Ending the sessions as part of the suspension covers the account
        // being reactivated before a session makes another request.
        $revoked = DB::transaction(function () use ($user, $reason): int {
            $user->forceFill([
                'status_before_suspension' => $user->status === AccountStatus::Suspended
                    ? $user->status_before_suspension
                    : $user->status->value,
                'status' => AccountStatus::Suspended,
                'suspended_at' => now(),
                'suspension_reason' => $reason,
                'remember_token' => Str::random(60),
            ])->save();

            return $this->policy->endOtherSessions($user);
        });

        $this->audit->record('account.suspended', AuditOutcome::Success, $user, [
            'actor_id' => $actor?->getKey(),
            'reason' => $reason,
            'sessions_revoked' => $revoked,
        ]);
    }

    /**
     * Close the account of someone who has left or lost their authorisation
     * (developer guide §6.1). Their sessions end and their roles are revoked,
     * so restoring the account later gives back no access by itself.
     */
    public function deactivate(User $user, string $reason, ?User $actor = null): void
    {
        $revoked = DB::transaction(function () use ($user, $reason, $actor): int {
            $user->forceFill([
                'status' => AccountStatus::Deactivated,
                'status_before_suspension' => null,
                'suspended_at' => null,
                'suspension_reason' => $reason,
                'remember_token' => Str::random(60),
            ])->save();

            $user->roleAssignments()->active()->update([
                'revoked_at' => now(),
                'revoked_by' => $actor?->getKey(),
                'revocation_reason' => 'Account deactivated: '.$reason,
            ]);

            $user->forgetActiveRoles();

            return $this->policy->endOtherSessions($user);
        });

        $this->audit->record('account.deactivated', AuditOutcome::Success, $user, [
            'actor_id' => $actor?->getKey(),
            'reason' => $reason,
            'sessions_revoked' => $revoked,
        ]);
    }

    /**
     * Lift a suspension, returning the account to the status it had. So an
     * account suspended before its owner activated it returns to Pending,
     * and the owner must still prove they control the email address through
     * the activation link; a deactivated account stays deactivated; an account
     * in use stays in use, even if its email address was never verified
     * (accounts that predate activation).
     *
     * An account that is not suspended (a deactivated one) has no earlier
     * status to return to, so it is restored to use.
     */
    public function reactivate(User $user, ?User $actor = null): AccountStatus
    {
        $previous = $user->status === AccountStatus::Suspended
            ? AccountStatus::tryFrom((string) $user->status_before_suspension)
            : null;

        $status = $previous ?? ($user->email_verified_at === null ? AccountStatus::Pending : AccountStatus::Active);

        $user->forceFill([
            'status' => $status,
            'status_before_suspension' => null,
            'suspended_at' => null,
            'suspension_reason' => null,
        ])->save();

        $this->audit->record('account.reactivated', AuditOutcome::Success, $user, [
            'actor_id' => $actor?->getKey(),
            'status' => $status->value,
        ]);

        return $status;
    }

    /**
     * Clear the user's authenticator so they must enrol again at next
     * sign-in (e.g. lost phone). Every session ends except $keepSession, the
     * session of a user replacing their own authenticator. Any other session
     * could otherwise enrol an authenticator of its own.
     */
    public function resetTwoFactor(User $user, ?User $actor = null, ?Session $keepSession = null, ?string $proof = null, ?string $recoveryCode = null): void
    {
        $revoked = $recoveryCode === null
            ? $this->clearTwoFactor($user, $keepSession)
            : $this->clearTwoFactorWithRecoveryCode($user, $keepSession, $recoveryCode);

        $this->audit->record('mfa.reset', AuditOutcome::Success, $user, [
            'actor_id' => $actor?->getKey(),
            'proof' => $proof,
            'sessions_revoked' => $revoked,
        ]);
    }

    /**
     * Activate the account with the chosen password, but only if it is still
     * pending and has not been invited again since $user was loaded (every
     * invitation gives a pending account a new random password, so an
     * unchanged password hash means the link checked is still the current
     * one). The check and the change are one conditional update, so of two
     * requests using the same link, or a link and a re-invite, only one wins.
     */
    private function claimPendingAccount(User $user, string $password): bool
    {
        $updated = User::query()
            ->whereKey($user->getKey())
            ->where('status', AccountStatus::Pending)
            ->where('password', $user->getRawOriginal('password'))
            ->update([
                'password' => Hash::make($password),
                'password_changed_at' => now(),
                'email_verified_at' => $user->email_verified_at ?? now(),
                'status' => AccountStatus::Active,
                'remember_token' => Str::random(60),
                'updated_at' => now(),
            ]);

        if ($updated !== 1) {
            return false;
        }

        $user->refresh();

        return true;
    }

    /**
     * Clear the authenticator with a recovery code as proof. The codes are
     * cleared only while they are still the stored value that held the code
     * (SecondFactor::useRecoveryCode), so a parallel request cannot use the
     * same code, and a reset that fails costs no code.
     *
     * @return int The number of stored sessions deleted.
     *
     * @throws ValidationException if the code is not (or no longer) valid.
     */
    private function clearTwoFactorWithRecoveryCode(User $user, ?Session $keepSession, string $recoveryCode): int
    {
        $revoked = null;

        $used = app(SecondFactor::class)->useRecoveryCode($user, $recoveryCode, function (string $storedCodes) use ($user, $keepSession, &$revoked): bool {
            $revoked = $this->clearTwoFactor($user, $keepSession, $storedCodes);

            return $revoked !== null;
        });

        if (! $used || $revoked === null) {
            throw ValidationException::withMessages(['recovery_code' => 'That recovery code is not valid.']);
        }

        $this->audit->record('mfa.recovery_code.used', AuditOutcome::Success, $user, ['purpose' => 'authenticator_reset']);

        return $revoked;
    }

    /**
     * Clear the authenticator and sign out other sessions, in one
     * transaction: no other session may see the account without an
     * authenticator before it has been signed out, or it could enrol one.
     *
     * @param  string|null  $onlyIfCodesAre  Clear only while the stored recovery codes are this value.
     * @return ($onlyIfCodesAre is null ? int : int|null) The number of stored sessions deleted, or
     *                                                    null when the codes had changed (nothing was cleared).
     */
    private function clearTwoFactor(User $user, ?Session $keepSession, ?string $onlyIfCodesAre = null): ?int
    {
        return DB::transaction(function () use ($user, $keepSession, $onlyIfCodesAre): ?int {
            $cleared = User::query()
                ->whereKey($user->getKey())
                ->when($onlyIfCodesAre !== null, fn ($query) => $query->where('two_factor_recovery_codes', $onlyIfCodesAre))
                ->update([
                    'two_factor_secret' => null,
                    'two_factor_recovery_codes' => null,
                    'two_factor_confirmed_at' => null,
                    // Invalidate any "remember me" cookie, which would otherwise let
                    // its holder sign back in and enrol their own authenticator.
                    'remember_token' => Str::random(60),
                    'updated_at' => now(),
                ]);

            if ($cleared !== 1) {
                if ($onlyIfCodesAre !== null) {
                    return null;
                }

                throw (new ModelNotFoundException)->setModel(User::class, [$user->getKey()]);
            }

            $user->refresh();

            return $this->policy->endOtherSessions($user, $keepSession);
        });
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array{User, string} The new account and its activation token.
     */
    private function createPendingAccount(array $details): array
    {
        $user = (new User)->forceFill($details);
        $user->save();

        return [$user, $this->invitationBroker()->createToken($user)];
    }

    /**
     * Replace the activation link first, so the old link stops working
     * before the account is marked as invited again (claimPendingAccount),
     * then update the account if it is still pending.
     *
     * The account row is locked before the link is touched: activation locks
     * the account and then the link too, so the two cannot deadlock.
     *
     * @param  array<string, mixed>  $details
     * @return string The new activation token.
     *
     * @throws ValidationException if the account was activated meanwhile.
     */
    private function reinvitePendingAccount(User $user, array $details): string
    {
        User::query()->whereKey($user->getKey())->lockForUpdate()->value('id');

        $token = $this->invitationBroker()->createToken($user);

        if (! $this->updatePendingAccount($user, $details)) {
            throw ValidationException::withMessages([
                'email' => 'An account with this email address has already been activated.',
            ]);
        }

        return $token;
    }

    /**
     * Update an account only while it is still pending, so a re-invite can
     * never turn an account activated a moment ago back into a pending one.
     *
     * @param  array<string, mixed>  $details
     */
    private function updatePendingAccount(User $user, array $details): bool
    {
        $updated = User::query()
            ->whereKey($user->getKey())
            ->where('status', AccountStatus::Pending)
            ->update([...$details, 'updated_at' => now()]);

        $user->refresh();

        return $updated === 1;
    }

    private function invitationBroker(): PasswordBroker
    {
        /** @var PasswordBroker */
        return Password::broker('invitations');
    }
}
