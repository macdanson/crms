<?php

namespace App\Services;

use App\Enums\AttemptResult;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Throwable;

/**
 * Limits guesses at a secret (password, authenticator code) per account
 * rather than per request, so a hijacked session cannot guess at the rate
 * of the general request limit.
 *
 * Attempts are counted in fixed windows of DECAY_SECONDS. The attempt is
 * counted before the secret is checked: the count is an atomic increment,
 * so parallel requests cannot all slip under the limit. A right guess, or
 * a check that could not answer, takes back its own attempt from the window
 * it was counted in, never from a later one; wrong guesses made elsewhere
 * (perhaps by someone else) still count until their window ends.
 */
class AccountAttemptLimiter
{
    /**
     * Wrong guesses allowed per account in each window.
     */
    public const MAX_ATTEMPTS = 5;

    /**
     * Length of a window, in seconds.
     */
    public const DECAY_SECONDS = 900;

    /**
     * Count an attempt, then run the check unless the limit is reached.
     *
     * @param  string  $secret  What is being guessed, e.g. "password".
     * @param  Closure(): bool  $check
     * @param  (Closure(): void)|null  $onLock  Run once per window, when the limit is first reached.
     */
    public function attempt(string $secret, User $user, Closure $check, ?Closure $onLock = null): AttemptResult
    {
        $key = $this->key($secret, $user);

        if (RateLimiter::hit($key, self::DECAY_SECONDS) > self::MAX_ATTEMPTS) {
            if ($onLock !== null && Cache::add($key.':locked', true, $this->secondsLeftInWindow())) {
                $onLock();
            }

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
     * Attempts counted in the current window.
     */
    public function attempts(string $secret, User $user): int
    {
        return RateLimiter::attempts($this->key($secret, $user));
    }

    /**
     * Whole minutes until the current window ends (at least 1).
     */
    public function minutesUntilUnlocked(): int
    {
        return max(1, (int) ceil($this->secondsLeftInWindow() / 60));
    }

    /**
     * The message shown once the limit is reached.
     *
     * @param  string  $guesses  What was guessed, in the plural, e.g. "passwords".
     */
    public function lockedMessage(string $guesses): string
    {
        $minutes = $this->minutesUntilUnlocked();

        return "Too many incorrect {$guesses}. Try again in {$minutes} ".Str::plural('minute', $minutes).'.';
    }

    /**
     * Take back one attempt from the window it was counted in. The count
     * never goes below zero.
     */
    private function takeBack(string $key): void
    {
        if (RateLimiter::decrement($key, self::DECAY_SECONDS) < 0) {
            RateLimiter::clear($key);
        }
    }

    /**
     * The counter for the current window.
     */
    private function key(string $secret, User $user): string
    {
        $window = intdiv(now()->getTimestamp(), self::DECAY_SECONDS);

        return "{$secret}-check:{$user->getKey()}:{$window}";
    }

    private function secondsLeftInWindow(): int
    {
        return self::DECAY_SECONDS - (now()->getTimestamp() % self::DECAY_SECONDS);
    }
}
