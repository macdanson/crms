<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Str;

/**
 * The session rules in one place (developer guide §6.1, §16): when a session
 * started, when it was last used, when it must end, and whether a security
 * change has made it stale.
 */
class SessionPolicy
{
    public const AUTHENTICATED_AT = 'crms.authenticated_at';

    public const LAST_ACTIVITY_AT = 'crms.last_activity_at';

    public const STAMP = 'crms.session_stamp';

    /**
     * Record a fresh sign-in.
     */
    public function start(Session $session, User $user): void
    {
        $now = now()->getTimestamp();

        $session->put([
            self::AUTHENTICATED_AT => $now,
            self::LAST_ACTIVITY_AT => $now,
            self::STAMP => $this->stampFor($user),
        ]);
    }

    /**
     * Record activity on the session.
     */
    public function touch(Session $session): void
    {
        $session->put([
            self::AUTHENTICATED_AT => $this->authenticatedAt($session),
            self::LAST_ACTIVITY_AT => now()->getTimestamp(),
        ]);
    }

    /**
     * Why the session must end now, or null if it may continue.
     *
     * @return 'idle_timeout'|'absolute_lifetime'|null
     */
    public function expiryReason(Session $session): ?string
    {
        $now = now()->getTimestamp();
        $lastActivityAt = (int) $session->get(self::LAST_ACTIVITY_AT, $now);
        $absoluteExpiresAt = $this->absoluteExpiresAt($session);

        return match (true) {
            $this->idleTimeout() > 0 && $now - $lastActivityAt >= $this->idleTimeout() => 'idle_timeout',
            $absoluteExpiresAt !== null && $now >= $absoluteExpiresAt => 'absolute_lifetime',
            default => null,
        };
    }

    /**
     * Seconds of inactivity before the session ends (0 = no limit).
     */
    public function idleTimeout(): int
    {
        return (int) config('crms.auth.idle_timeout') * 60;
    }

    /**
     * Unix time at which the session ends however active it is, or null when
     * there is no maximum length.
     */
    public function absoluteExpiresAt(Session $session): ?int
    {
        $lifetime = (int) config('crms.auth.absolute_lifetime') * 60;

        return $lifetime > 0 ? $this->authenticatedAt($session) + $lifetime : null;
    }

    /**
     * Seconds until the maximum length is reached, or null when there is none.
     */
    public function absoluteRemaining(Session $session): ?int
    {
        $expiresAt = $this->absoluteExpiresAt($session);

        return $expiresAt === null ? null : max(0, $expiresAt - now()->getTimestamp());
    }

    /**
     * Whether the session predates a security change that ended other
     * sessions. A session without a stamp (signed in before stamps existed)
     * adopts the current one.
     */
    public function isCurrent(Session $session, User $user): bool
    {
        $stamp = $this->stampFor($user);

        if (! $session->has(self::STAMP)) {
            $session->put(self::STAMP, $stamp);

            return true;
        }

        return hash_equals($stamp, (string) $session->get(self::STAMP));
    }

    /**
     * End every session of the user except $keep, with any session driver.
     */
    public function endOtherSessions(User $user, ?Session $keep = null): void
    {
        $user->forceFill(['session_stamp' => Str::random(64)])->saveQuietly();

        $keep?->put(self::STAMP, $user->session_stamp);
    }

    private function authenticatedAt(Session $session): int
    {
        return (int) $session->get(self::AUTHENTICATED_AT, now()->getTimestamp());
    }

    private function stampFor(User $user): string
    {
        if ($user->session_stamp === null) {
            $user->forceFill(['session_stamp' => Str::random(64)])->saveQuietly();
        }

        return (string) $user->session_stamp;
    }
}
