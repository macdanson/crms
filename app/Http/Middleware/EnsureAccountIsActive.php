<?php

namespace App\Http\Middleware;

use App\Enums\AuditOutcome;
use App\Models\User;
use App\Services\SessionTermination;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends the session of a user whose account is no longer active, so a
 * suspension takes effect on the very next request (developer guide §25.2 #5).
 */
class EnsureAccountIsActive
{
    public function __construct(private SessionTermination $termination) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && ! $user->isActive()) {
            return $this->termination->end(
                $request,
                $user,
                'auth.session.terminated',
                AuditOutcome::Blocked,
                ['reason' => 'account_'.$user->status->value],
                'Your session has ended because your account is no longer active.',
            );
        }

        return $next($request);
    }
}
