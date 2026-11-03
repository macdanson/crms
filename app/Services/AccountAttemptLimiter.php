<?php

namespace App\Services;

use App\Enums\AttemptResult;
use App\Models\User;
use Carbon\CarbonImmutable;
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
 * About MAX_ATTEMPTS wrong guesses are allowed in any DECAY_SECONDS. Guesses
 * are counted in fixed windows, and the previous window's count is added in
 * proportion to how much of it still falls within the last DECAY_SECONDS (a
 * sliding-window estimate), so a burst either side of a window boundary is
 * still limited.
 *
 * The attempt is counted before the secret is checked: the count is an
 * atomic increment, so parallel requests cannot all slip under the limit.
 * A right guess, a check that could not answer, and a refused guess take
 * back their own attempt from the window it was counted in; wrong guesses
 * made elsewhere (perhaps by someone else) still count.
 */
class AccountAttemptLimiter
{
    /**
     * Wrong guesses allowed in any DECAY_SECONDS.
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
     * @param  (Closure(): void)|null  $onLock  Run once per lock, as soon as the
     *                                          limit is reached: by the last wrong guess allowed, or at
     *                                          the latest by the first guess refused.
     */
    public function attempt(string $secret, User $user, Closure $check, ?Closure $onLock = null): AttemptResult
    {
        $window = $this->window();
        $key = $this->key($secret, $user, $window);

        // Kept for two windows: in the next one it is the previous count.
        $hits = RateLimiter::hit($key, 2 * self::DECAY_SECONDS);

        if ($this->exceedsLimit($hits, $this->countIn($secret, $user, $window - 1))) {
            // A refused guess is not counted, so asking again does not
            // lengthen the lock.
            $this->takeBack($key);
            $this->reachedLimit($secret, $user, $onLock);

            return AttemptResult::Locked;
        }

        try {
            $passed = $check();
        } catch (Throwable $exception) {
            $this->takeBack($key);

            throw $exception;
        }

        if (! $passed) {
            if ($this->exceedsLimit($hits + 1, $this->countIn($secret, $user, $window - 1))) {
                $this->reachedLimit($secret, $user, $onLock);
            }

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
        return $this->countIn($secret, $user, $this->window());
    }

    /**
     * When the next guess will be allowed (now, if it already is).
     */
    public function unlocksAt(string $secret, User $user): CarbonImmutable
    {
        $now = now()->getTimestamp();
        $window = $this->window();
        $windowStart = $window * self::DECAY_SECONDS;
        $current = $this->countIn($secret, $user, $window);
        $previous = $this->countIn($secret, $user, $window - 1);

        // Later in this window, as the previous window's share shrinks...
        $offset = $this->earliestAllowedOffset($current + 1, $previous);

        if ($offset !== null) {
            return CarbonImmutable::createFromTimestamp(max($now, $windowStart + $offset));
        }

        // ...or in the next window, where this window becomes the previous one.
        $offset = $this->earliestAllowedOffset(1, $current) ?? self::DECAY_SECONDS;

        return CarbonImmutable::createFromTimestamp($windowStart + self::DECAY_SECONDS + $offset);
    }

    /**
     * The message shown once the limit is reached.
     *
     * @param  string  $guesses  What was guessed, in the plural, e.g. "passwords".
     */
    public function lockedMessage(string $secret, User $user, string $guesses): string
    {
        $seconds = $this->unlocksAt($secret, $user)->getTimestamp() - now()->getTimestamp();
        $minutes = max(1, (int) ceil($seconds / 60));

        return "Too many incorrect {$guesses}. Try again in {$minutes} ".Str::plural('minute', $minutes).'.';
    }

    /**
     * Whether $current guesses in this window, with $previous in the one
     * before, are over the limit. In integer arithmetic:
     * current + previous × (time left in this window ÷ window length) > max.
     */
    private function exceedsLimit(int $current, int $previous): bool
    {
        $secondsLeft = self::DECAY_SECONDS - (now()->getTimestamp() % self::DECAY_SECONDS);

        return $current * self::DECAY_SECONDS + $previous * $secondsLeft > self::MAX_ATTEMPTS * self::DECAY_SECONDS;
    }

    /**
     * The earliest offset into a window at which $current guesses in it, with
     * $previous in the window before, are within the limit; null if never in
     * that window.
     */
    private function earliestAllowedOffset(int $current, int $previous): ?int
    {
        $room = self::MAX_ATTEMPTS - $current;

        if ($room < 0) {
            return null;
        }

        if ($previous === 0) {
            return 0;
        }

        // Needs previous × (DECAY − offset) ≤ room × DECAY.
        $offset = self::DECAY_SECONDS - intdiv($room * self::DECAY_SECONDS, $previous);

        return $offset < self::DECAY_SECONDS ? max(0, $offset) : null;
    }

    /**
     * Run $onLock once for this lock (until guesses are allowed again).
     *
     * @param  (Closure(): void)|null  $onLock
     */
    private function reachedLimit(string $secret, User $user, ?Closure $onLock): void
    {
        if ($onLock === null) {
            return;
        }

        $lockLasts = max(1, $this->unlocksAt($secret, $user)->getTimestamp() - now()->getTimestamp());

        if (Cache::add("{$secret}-check:{$user->getKey()}:locked", true, $lockLasts)) {
            $onLock();
        }
    }

    /**
     * Take back one attempt from the window it was counted in. The count
     * never goes below zero.
     */
    private function takeBack(string $key): void
    {
        if (RateLimiter::decrement($key, 2 * self::DECAY_SECONDS) < 0) {
            RateLimiter::clear($key);
        }
    }

    private function countIn(string $secret, User $user, int $window): int
    {
        return RateLimiter::attempts($this->key($secret, $user, $window));
    }

    private function window(): int
    {
        return intdiv(now()->getTimestamp(), self::DECAY_SECONDS);
    }

    private function key(string $secret, User $user, int $window): string
    {
        return "{$secret}-check:{$user->getKey()}:{$window}";
    }
}
