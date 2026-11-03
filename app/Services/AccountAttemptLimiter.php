<?php

namespace App\Services;

use App\Enums\AttemptResult;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Throwable;

/**
 * Limits guesses at a secret (password, authenticator code) made from inside
 * a signed-in session, per account rather than per request, so a hijacked
 * session cannot guess at the rate of the general request limit.
 *
 * The attempt is counted before the secret is checked: the count is an
 * atomic increment, so parallel requests cannot all slip under the limit.
 * A right guess, or a check that could not answer, takes back only its own
 * attempt: wrong guesses made elsewhere (perhaps by someone else) still
 * count until the window ends.
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
     *
     * @param  string  $secret  What is being guessed, e.g. "password".
     * @param  Closure(): bool  $check
     */
    public function attempt(string $secret, User $user, Closure $check): AttemptResult
    {
        $key = $this->key($secret, $user);

        if (RateLimiter::hit($key, self::DECAY_SECONDS) > self::MAX_ATTEMPTS) {
            return AttemptResult::Locked;
        }

        try {
            $passed = $check();
        } catch (Throwable $exception) {
            $this->takeBack($key);

            throw $exception;
        }

        if (! $passed) {
            return AttemptResult::Failed;
        }

        $this->takeBack($key);

        return AttemptResult::Passed;
    }

    /**
     * Take back one counted attempt. If the window ended while the check ran,
     * there is nothing to take back, and the count must not go below zero.
     */
    private function takeBack(string $key): void
    {
        if (RateLimiter::decrement($key, self::DECAY_SECONDS) < 0) {
            RateLimiter::clear($key);
        }
    }

    /**
     * The message shown once the limit is reached.
     *
     * @param  string  $guesses  What was guessed, in the plural, e.g. "passwords".
     */
    public function lockedMessage(string $secret, User $user, string $guesses): string
    {
        $minutes = max(1, (int) ceil(RateLimiter::availableIn($this->key($secret, $user)) / 60));

        return "Too many incorrect {$guesses}. Try again in {$minutes} ".Str::plural('minute', $minutes).'.';
    }

    private function key(string $secret, User $user): string
    {
        return "{$secret}-check:{$user->getKey()}";
    }
}
