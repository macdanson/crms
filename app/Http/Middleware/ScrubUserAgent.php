<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes the User-Agent header safe to store. A client can send any bytes,
 * and Postgres and strict MySQL refuse invalid text, which would turn the
 * session save or the audit record into a server error.
 *
 * It is cut to the length the database session driver keeps, on a character
 * boundary, because the driver itself cuts by bytes.
 */
class ScrubUserAgent
{
    private const MAX_BYTES = 500;

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $agent = $request->headers->get('User-Agent');

        if ($agent !== null) {
            $request->headers->set('User-Agent', mb_strcut(str_replace("\0", '', mb_scrub($agent, 'UTF-8')), 0, self::MAX_BYTES, 'UTF-8'));
        }

        return $next($request);
    }
}
