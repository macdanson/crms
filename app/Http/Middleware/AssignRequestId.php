<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every request a correlation ID that is attached to logs, audit
 * events and the response, so incidents can be traced end to end (§12.2).
 *
 * An incoming X-Request-Id is only reused from a trusted proxy (configure
 * with trustProxies() in bootstrap/app.php). Anyone else could otherwise
 * file their actions under another user's request ID.
 */
class AssignRequestId
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $incoming = (string) $request->headers->get('X-Request-Id');

        $requestId = $request->isFromTrustedProxy() && preg_match('/^[A-Za-z0-9\-]{8,64}$/', $incoming) === 1
            ? $incoming
            : (string) Str::uuid();

        Context::add('request_id', $requestId);

        $response = $next($request);

        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }
}
