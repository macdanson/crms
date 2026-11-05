<?php

namespace App\Http\Middleware;

use App\Services\SessionTermination;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies browser hardening headers (developer guide §16).
 *
 * Authenticated responses, and the response that ends a session, are marked
 * no-store so sensitive pages are not kept by shared browsers or
 * intermediary caches. Registered as global middleware so it wraps every
 * response, including those other middleware return early.
 */
class SetSecurityHeaders
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $cspEnabled = (bool) config('crms.security.csp');

        if ($cspEnabled) {
            Vite::useCspNonce();
        }

        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');

        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        // A response may set a stricter policy of its own, such as the sandbox
        // around a previewed document.
        if ($cspEnabled && ! $headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy', $this->contentSecurityPolicy());
        }

        if ($this->isSensitive($request)) {
            $headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
            $headers->set('Pragma', 'no-cache');
        }

        return $response;
    }

    private function isSensitive(Request $request): bool
    {
        return $request->user() !== null
            || SessionTermination::isTerminating($request)
            || $request->routeIs('password.reset', 'activation.create', 'two-factor.login');
    }

    private function contentSecurityPolicy(): string
    {
        $nonce = Vite::cspNonce();

        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'",
            // Element Plus positions popovers and dialogs with inline styles.
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: blob:",
            "font-src 'self' data:",
            "connect-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]);
    }
}
