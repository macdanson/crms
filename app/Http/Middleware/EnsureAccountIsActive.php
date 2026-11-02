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
 * Ends the session of an officer whose account is no longer active, so a
 * suspension takes effect on the very next request (developer guide §25.2 #5).
 */
class EnsureAccountIsActive
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

        if ($user instanceof User && ! $user->isActive()) {
            $this->audit->record('auth.session.terminated', AuditOutcome::Blocked, $user, [
                'reason' => 'account_'.$user->status->value,
            ]);

            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('status', 'Your session has ended because your account is no longer active.');
        }

        return $next($request);
    }
}
