<?php

namespace App\Http\Middleware;

use App\Http\ResponseFormat;
use App\Models\User;
use App\Services\SecondFactor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards Fortify's authenticator management routes, which Fortify itself
 * only protects with password confirmation. Once an authenticator is
 * confirmed:
 *
 * - its secret and QR code are never shown again;
 * - viewing or replacing recovery codes, or switching multi-factor
 *   authentication off, needs a recent proof of the second factor.
 *
 * Fortify registers these routes itself, so they are matched by name.
 */
class RequireSecondFactorConfirmation
{
    /**
     * Routes that reveal the authenticator secret.
     */
    private const SECRET_ROUTES = ['two-factor.secret-key', 'two-factor.qr-code'];

    /**
     * Routes that need a recent proof of the second factor.
     */
    private const PROTECTED_ROUTES = [
        'two-factor.recovery-codes',
        'two-factor.regenerate-recovery-codes',
        'two-factor.disable',
    ];

    public function __construct(private SecondFactor $secondFactor) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->hasEnabledTwoFactorAuthentication()) {
            return $next($request);
        }

        if ($request->routeIs(...self::SECRET_ROUTES)) {
            abort(403, 'The secret of a linked authenticator is never shown again.');
        }

        if ($request->routeIs(...self::PROTECTED_ROUTES) && ! $this->secondFactor->isRecentlyConfirmed($request->session())) {
            $message = 'Confirm it’s you with your authenticator app first.';

            return ResponseFormat::wantsJson($request)
                ? response()->json(['message' => $message, 'second_factor_required' => true], 423)
                : back()->with('error', $message);
        }

        return $next($request);
    }
}
