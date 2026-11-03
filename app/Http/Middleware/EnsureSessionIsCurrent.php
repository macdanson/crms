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
 * Ends a session that a security change (such as an authenticator reset) has
 * made stale. Unlike deleting stored sessions, this works with every session
 * driver.
 */
class EnsureSessionIsCurrent
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

        if ($user instanceof User && $request->hasSession() && ! $this->policy->isCurrent($request->session(), $user)) {
            return $this->termination->end(
                $request,
                $user,
                'auth.session.invalidated',
                AuditOutcome::Success,
                ['reason' => 'security_change'],
                'You were signed out because the security settings of your account changed. Please sign in again.',
            );
        }

        return $next($request);
    }
}
