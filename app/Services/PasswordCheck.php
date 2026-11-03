<?php

namespace App\Services;

use App\Enums\AuditOutcome;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Checks the password of a user who is already signed in (password
 * confirmation, changing the password, signing out other sessions).
 *
 * Failures are audited and limited per account, so a hijacked session
 * cannot guess the password at the rate of the general request limit.
 */
class PasswordCheck
{
    /**
     * Wrong passwords allowed per account before checks are refused.
     */
    private const MAX_ATTEMPTS = 5;

    /**
     * How long the attempts are counted for, in seconds.
     */
    private const DECAY_SECONDS = 900;

    public function __construct(private AuditLogger $audit) {}

    /**
     * Check the password. Returns null when it is correct, otherwise the
     * message to show.
     *
     * @param  string  $purpose  What the check was for, kept in the audit record.
     */
    public function check(User $user, string $password, string $purpose, string $wrongPasswordMessage): ?string
    {
        $key = 'password-check:'.$user->getKey();

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            $this->audit->record('auth.password_check.locked', AuditOutcome::Blocked, $user, ['purpose' => $purpose]);

            $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);

            return "Too many incorrect passwords. Try again in {$minutes} minutes.";
        }

        if (Hash::check($password, $user->password)) {
            RateLimiter::clear($key);

            return null;
        }

        RateLimiter::hit($key, self::DECAY_SECONDS);

        $this->audit->record('auth.password_check.failed', AuditOutcome::Failure, $user, ['purpose' => $purpose]);

        return $wrongPasswordMessage;
    }
}
