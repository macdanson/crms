<?php

namespace App\Http;

use Illuminate\Http\Request;

/**
 * Decides whether a request gets a JSON response or a redirect/page.
 *
 * A plain class rather than a request macro, so the exception handler can
 * use it even for errors raised before the service providers have booted.
 */
final class ResponseFormat
{
    /**
     * API routes and plain fetch/XHR clients (such as the session heartbeat)
     * get JSON. Inertia visits send Accept: text/html, so they get redirects
     * and pages.
     */
    public static function wantsJson(Request $request): bool
    {
        return $request->is('api/*') || $request->expectsJson();
    }
}
