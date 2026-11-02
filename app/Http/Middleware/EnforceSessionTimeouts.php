<?php

namespace App\Http\Middleware;

use App\Enums\AuditOutcome;
use App\Models\User;
use App\Services\AuditLogger;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces the idle timeout and absolute session lifetime on the server, so
 * they hold even if the browser-side idle timer is bypassed (§6.1, §16).
 */
class EnforceSessionTimeouts
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $request->hasSession()) {
            return $next($request);
        }

        $session = $request->session();
        $now = now()->getTimestamp();

        $authenticatedAt = (int) $session->get('crms.authenticated_at', $now);
        $lastActivityAt = (int) $session->get('crms.last_activity_at', $now);

        $idleLimit = (int) config('crms.auth.idle_timeout') * 60;
        $absoluteLimit = (int) config('crms.auth.absolute_lifetime') * 60;

        $reason = match (true) {
            $idleLimit > 0 && $now - $lastActivityAt >= $idleLimit => 'idle_timeout',
            $absoluteLimit > 0 && $now - $authenticatedAt >= $absoluteLimit => 'absolute_lifetime',
            default => null,
        };

        if ($reason !== null) {
            return $this->expire($request, $user, $reason);
        }

        $session->put([
            'crms.authenticated_at' => $authenticatedAt,
            'crms.last_activity_at' => $now,
        ]);

        return $next($request);
    }

    private function expire(Request $request, User $user, string $reason): Response
    {
        $this->audit->record('auth.session.expired', AuditOutcome::Success, $user, ['reason' => $reason]);

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $message = $reason === 'idle_timeout'
            ? 'You were signed out after a period of inactivity.'
            : 'Your session reached its maximum length. Please sign in again.';

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => $message], 401);
        }

        return redirect()->route('login')->with('status', $message);
    }
}
