<?php

namespace App\Services;

use App\Enums\AuditOutcome;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Limits guesses at a secret (password, authenticator code) made from inside
 * a signed-in session, per account rather than per request, so a hijacked
 * session cannot guess at the rate of the general request limit.
 *
 * The attempt is counted before the secret is checked: the count is an
 * atomic increment, so parallel requests cannot all slip under the limit.
 */
class AccountAttemptLimiter
{
    /**
     * Wrong guesses allowed per account in each window.
     */
    public const MAX_ATTEMPTS = 5;

    /**
     * How long attempts are counted for, in seconds.
     */
    public const DECAY_SECONDS = 900;

    /**
     * Count an attempt, then run the check unless the limit is reached.
     * Success clears the count.
     *
     * @param  string  $secret  What is being guessed, e.g. "password".
     * @param  Closure(): bool  $check
     * @return AuditOutcome Success, Failure (wrong guess) or Blocked (limit reached).
     */
    public function attempt(string $secret, User $user, Closure $check): AuditOutcome
    {
        $key = $this->key($secret, $user);

        if (RateLimiter::hit($key, self::DECAY_SECONDS) > self::MAX_ATTEMPTS) {
            return AuditOutcome::Blocked;
        }

        if (! $check()) {
            return AuditOutcome::Failure;
        }

        RateLimiter::clear($key);

        return AuditOutcome::Success;
    }

    /**
     * The message shown once the limit is reached.
     */
    public function lockedMessage(string $secret, User $user, string $guesses): string
    {
        $minutes = max(1, (int) ceil(RateLimiter::availableIn($this->key($secret, $user)) / 60));

        return "Too many incorrect {$guesses}. Try again in {$minutes} minutes.";
    }

    private function key(string $secret, User $user): string
    {
        return "{$secret}-check:{$user->getKey()}";
    }
}
