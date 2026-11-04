<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Str;

/**
 * The session rules in one place (developer guide §6.1, §16): when a session
 * started, when it was last used, when it must end, and whether a security
 * change has made it stale.
 *
 * Every action that must sign out a user's other sessions (password change or
 * reset, "sign out other sessions", authenticator reset, suspension) calls
 * endOtherSessions(). It works with every session driver and leaves the
 * password hash alone.
 */
class SessionPolicy
{
    public const AUTHENTICATED_AT = 'crms.authenticated_at';

    public const LAST_ACTIVITY_AT = 'crms.last_activity_at';

    public const STAMP = 'crms.session_stamp';

    /**
     * The sign-in code step in progress: when it began, and the account's
     * session stamp then.
     */
    public const CHALLENGE = 'crms.two_factor_challenge';

    public function __construct(private BrowserSessions $sessions) {}

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
     * Record that a guest has passed the password step and must now give a
     * code (Fortify keeps the account in "login.id").
     */
    public function startChallenge(Session $session, User $user): void
    {
        $session->put(self::CHALLENGE, [
            'started_at' => now()->getTimestamp(),
            'stamp' => $this->stampFor($user),
        ]);
    }

    /**
     * Whether the code step may still be completed: it began recently (a
     * challenge timeout of 0 means no limit), and no
     * password change or reset (which rotates the session stamp) has
     * happened since the password was given.
     */
    public function challengeIsCurrent(Session $session, User $user): bool
    {
        $challenge = $session->get(self::CHALLENGE);

        if (! is_array($challenge) || ! is_int($challenge['started_at'] ?? null) || ! is_string($challenge['stamp'] ?? null)) {
            return false;
        }

        $timeout = (int) config('crms.auth.challenge_timeout') * 60;

        return ($timeout === 0 || now()->getTimestamp() - $challenge['started_at'] < $timeout)
            && $user->session_stamp !== null
            && hash_equals($user->session_stamp, $challenge['stamp']);
    }

    /**
     * Abandon the code step, so the password must be given again.
     */
    public function endChallenge(Session $session): void
    {
        $session->forget(['login.id', 'login.remember', self::CHALLENGE]);
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
     * Whether the session was stamped at sign-in and no security change has
     * ended it since. A session without a stamp is stale: every sign-in
     * stamps its session, so a missing stamp cannot be trusted.
     */
    public function isCurrent(Session $session, User $user): bool
    {
        $sessionStamp = $session->get(self::STAMP);

        if (! is_string($sessionStamp) || $user->session_stamp === null) {
            return false;
        }

        if (hash_equals($user->session_stamp, $sessionStamp)) {
            return true;
        }

        // Sessions are not locked, so a request the kept session had in
        // flight can save the old stamp back over the new one. The kept
        // session is recognised by its ID instead.
        return $user->session_stamp_holder !== null
            && hash_equals($user->session_stamp_holder, $this->holderHash($session));
    }

    /**
     * End every session of the user except $keep, with any session driver.
     * Stored sessions (database driver only) are also deleted, so they leave
     * the session list. Returns how many stored sessions were deleted.
     *
     * Call it in the same database transaction as the change that requires
     * it, so no other session sees the change before it has ended.
     */
    public function endOtherSessions(User $user, ?Session $keep = null): int
    {
        $user->forceFill([
            'session_stamp' => Str::random(64),
            'session_stamp_holder' => $keep === null ? null : $this->holderHash($keep),
        ])->saveQuietly();

        if ($keep === null) {
            return $this->sessions->revokeAll($user);
        }

        $keep->put(self::STAMP, $user->session_stamp);

        return $this->sessions->revokeOthers($user, $keep->getId());
    }

    /**
     * The stamp a session of this user must carry to stay signed in.
     */
    public function stampFor(User $user): string
    {
        if ($user->session_stamp === null) {
            // Only a row inserted without Eloquent lacks a stamp. Setting it
            // only while it is still empty keeps concurrent sign-ins on the
            // same stamp.
            User::query()->whereKey($user->getKey())->whereNull('session_stamp')
                ->update(['session_stamp' => Str::random(64)]);

            $user->session_stamp = User::query()->whereKey($user->getKey())->value('session_stamp');
            $user->syncOriginalAttribute('session_stamp');
        }

        return (string) $user->session_stamp;
    }

    private function authenticatedAt(Session $session): int
    {
        return (int) $session->get(self::AUTHENTICATED_AT, now()->getTimestamp());
    }

    /**
     * A keyed hash of the session ID, so the ID itself is never stored.
     */
    private function holderHash(Session $session): string
    {
        return $this->sessions->sessionHash($session->getId());
    }
}
