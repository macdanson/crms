<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends users who have not finished authenticator enrolment to the
 * mandatory setup screen before they can reach any operational page (§6.1).
 */
class EnsureTwoFactorIsEnrolled
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (
            config('crms.auth.require_mfa')
            && $user instanceof User
            && ! $user->hasEnabledTwoFactorAuthentication()
        ) {
            if ($request->expectsJson() && ! $request->header('X-Inertia')) {
                return response()->json(['message' => 'Multi-factor authentication enrolment is required.'], 403);
            }

            return redirect()->route('two-factor.setup');
        }

        return $next($request);
    }
}
