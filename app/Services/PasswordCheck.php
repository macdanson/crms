<?php

namespace App\Services;

use App\Enums\AttemptResult;
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
        $result = $this->limiter->attempt('password', $user, fn (): bool => Hash::check($password, $user->password));

        if ($result === AttemptResult::Passed) {
            return null;
        }

        $locked = $result === AttemptResult::Locked;

        $this->audit->record(
            $locked ? 'auth.password_check.locked' : 'auth.password_check.failed',
            $locked ? AuditOutcome::Blocked : AuditOutcome::Failure,
            $user,
            ['purpose' => $purpose],
        );

        return $locked ? $this->limiter->lockedMessage('passwords') : $wrongPasswordMessage;
    }
}
