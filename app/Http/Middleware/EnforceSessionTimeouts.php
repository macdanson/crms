<?php

namespace App\Http\Middleware;

use App\Enums\AuditOutcome;
use App\Models\User;
use App\Services\SessionPolicy;
use App\Services\SessionTermination;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces the idle timeout and absolute session lifetime on the server, so
 * they hold even if the browser-side idle timer is bypassed (§6.1, §16).
 */
class EnforceSessionTimeouts
{
    public function __construct(
        private SessionPolicy $policy,
        private SessionTermination $termination,
    ) {}

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

        $reason = $this->policy->expiryReason($request->session());

        if ($reason !== null) {
            return $this->expire($request, $user, $reason);
        }

        $this->policy->touch($request->session());

        return $next($request);
    }

    private function expire(Request $request, User $user, string $reason): Response
    {
        return $this->termination->end(
            $request,
            $user,
            'auth.session.expired',
            AuditOutcome::Success,
            ['reason' => $reason],
            $reason === 'idle_timeout'
                ? 'You were signed out after a period of inactivity.'
                : 'Your session reached its maximum length. Please sign in again.',
        );
    }
}
