<?php

namespace App\Services;

use App\Enums\AuditOutcome;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * Checks the password of a user who is already signed in (password
 * confirmation, changing the password, signing out other sessions).
 *
 * Failures are audited and limited per account (AccountAttemptLimiter).
 */
class PasswordCheck
{
    public function __construct(
        private AuditLogger $audit,
        private AccountAttemptLimiter $limiter,
    ) {}

    /**
     * Check the password. Returns null when it is correct, otherwise the
     * message to show.
     *
     * @param  string  $purpose  What the check was for, kept in the audit record.
     */
    public function check(User $user, string $password, string $purpose, string $wrongPasswordMessage): ?string
    {
        $outcome = $this->limiter->attempt('password', $user, fn (): bool => Hash::check($password, $user->password));

        return match ($outcome) {
            AuditOutcome::Success => null,
            AuditOutcome::Blocked => $this->refuse('auth.password_check.locked', $outcome, $user, $purpose, $this->limiter->lockedMessage('password', $user, 'passwords')),
            AuditOutcome::Failure => $this->refuse('auth.password_check.failed', $outcome, $user, $purpose, $wrongPasswordMessage),
        };
    }

    private function refuse(string $event, AuditOutcome $outcome, User $user, string $purpose, string $message): string
    {
        $this->audit->record($event, $outcome, $user, ['purpose' => $purpose]);

        return $message;
    }
}
