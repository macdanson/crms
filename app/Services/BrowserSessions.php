<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lists and revokes a user's server-side sessions.
 *
 * Raw session identifiers never leave the server: each session is exposed
 * through a keyed hash that is only meaningful to this application.
 */
class BrowserSessions
{
    public function __construct(private KeyedHash $keyedHash) {}

    public function supported(): bool
    {
        return config('session.driver') === 'database';
    }

    /**
     * @return Collection<int, array{id: string, ip_address: string|null, browser: string, platform: string, is_mobile: bool, is_current: bool, last_active_at: string}>
     */
    public function forUser(User $user, string $currentSessionId): Collection
    {
        if (! $this->supported()) {
            return collect();
        }

        return $this->query($user)
            ->where('last_activity', '>=', now()->subSeconds($this->aliveForSeconds())->getTimestamp())
            ->orderByDesc('last_activity')
            ->get(['id', 'ip_address', 'user_agent', 'last_activity'])
            ->map(function (object $session) use ($currentSessionId): array {
                $agent = $this->describeAgent((string) $session->user_agent);

                return [
                    'id' => $this->publicId($session->id),
                    'ip_address' => is_string($session->ip_address) ? $session->ip_address : null,
                    'browser' => $agent['browser'],
                    'platform' => $agent['platform'],
                    'is_mobile' => $agent['is_mobile'],
                    'is_current' => hash_equals($session->id, $currentSessionId),
                    'last_active_at' => Carbon::createFromTimestamp($session->last_activity)->toIso8601String(),
                ];
            });
    }

    /**
     * Revoke a single session belonging to the user. Returns false when the
     * public identifier does not match one of the user's sessions.
     */
    public function revoke(User $user, string $publicId, string $currentSessionId): bool
    {
        if (! $this->supported()) {
            return false;
        }

        $sessionId = $this->query($user)
            ->pluck('id')
            ->first(fn (string $id): bool => hash_equals($this->publicId($id), $publicId));

        if ($sessionId === null || hash_equals($sessionId, $currentSessionId)) {
            return false;
        }

        return $this->query($user)->where('id', $sessionId)->delete() > 0;
    }

    /**
     * Revoke every session for the user except the given one.
     */
    public function revokeOthers(User $user, string $currentSessionId): int
    {
        if (! $this->supported()) {
            return 0;
        }

        return $this->query($user)->where('id', '!=', $currentSessionId)->delete();
    }

    /**
     * Revoke every session for the user (suspension, password reset).
     */
    public function revokeAll(User $user): int
    {
        if (! $this->supported()) {
            return 0;
        }

        return $this->query($user)->delete();
    }

    /**
     * A keyed one-way hash of a session ID. The audit log, the kept-session
     * marker and the public session IDs below all derive from it, so they
     * stay linked.
     */
    public function sessionHash(string $sessionId): string
    {
        return $this->keyedHash->of($sessionId);
    }

    /**
     * The identifier the session list shows for a session.
     */
    public function publicId(string $sessionId): string
    {
        return substr($this->sessionHash($sessionId), 0, 40);
    }

    /**
     * A deliberately small user-agent parser; enough for users to recognise
     * their own devices without adding a dependency.
     *
     * @return array{browser: string, platform: string, is_mobile: bool}
     */
    public function describeAgent(string $userAgent): array
    {
        $browser = match (true) {
            str_contains($userAgent, 'Edg/') => 'Microsoft Edge',
            str_contains($userAgent, 'OPR/') => 'Opera',
            str_contains($userAgent, 'Firefox/') => 'Firefox',
            str_contains($userAgent, 'Chrome/') => 'Chrome',
            str_contains($userAgent, 'Safari/') => 'Safari',
            default => 'Unknown browser',
        };

        $platform = match (true) {
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'iPhone'), str_contains($userAgent, 'iPad') => 'iOS',
            str_contains($userAgent, 'Mac OS X') => 'macOS',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => 'Unknown OS',
        };

        return [
            'browser' => $browser,
            'platform' => $platform,
            'is_mobile' => (bool) preg_match('/Mobile|Android|iPhone|iPad/i', $userAgent),
        ];
    }

    /**
     * How long since its last request a session can still be alive. Older
     * stored sessions have ended; the row just has not been cleaned up yet.
     */
    private function aliveForSeconds(): int
    {
        $lifetime = (int) config('session.lifetime') * 60;
        $idleTimeout = (int) config('crms.auth.idle_timeout') * 60;

        return $idleTimeout > 0 ? min($lifetime, $idleTimeout) : $lifetime;
    }

    private function query(User $user): Builder
    {
        return DB::connection(config('session.connection'))
            ->table(config('session.table', 'sessions'))
            ->where('user_id', $user->getAuthIdentifier());
    }
}
