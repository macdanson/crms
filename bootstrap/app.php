<?php

use App\Enums\AuditOutcome;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnforceSessionTimeouts;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureSessionIsCurrent;
use App\Http\Middleware\EnsureTwoFactorIsEnrolled;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequirePasswordConfirmation;
use App\Http\Middleware\RequireSecondFactorConfirmation;
use App\Http\Middleware\ScrubUserAgent;
use App\Http\Middleware\SetSecurityHeaders;
use App\Http\ResponseFormat;
use App\Services\AuditLogger;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Redirects after a PUT, PATCH or DELETE: inertia-laravel pushes its
        // EnsureGetOnRedirect onto the global middleware at boot, after the
        // global middleware appended here. It therefore wraps routing: the
        // web group below, route middleware, controllers and the exception
        // handler. A 302 from any of those that answers an Inertia PUT, PATCH
        // or DELETE becomes a 303, so the browser follows it with GET (after a
        // POST it does so anyway). The global middleware appended here run
        // outside it and must not redirect.

        // Appended so they run after TrustProxies (configured in
        // config/trustedproxy.php), which they rely on. Being
        // global, the security headers also cover responses that a later
        // middleware returns early (session ended, CSRF expired, 404).
        $middleware->append([ScrubUserAgent::class, AssignRequestId::class, SetSecurityHeaders::class]);

        $middleware->web(append: [
            EnforceSessionTimeouts::class,
            EnsureAccountIsActive::class,
            EnsureSessionIsCurrent::class,
            RequireSecondFactorConfirmation::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'mfa' => EnsureTwoFactorIsEnrolled::class,
            'password.confirm' => RequirePasswordConfirmation::class,
        ]);

        // Keep any message flashed when the session ended (such as after a
        // heartbeat found it timed out) for the sign-in page this redirect
        // leads to; otherwise the redirect itself would use it up.
        $middleware->redirectGuestsTo(function (Request $request): string {
            if ($request->hasSession()) {
                $request->session()->reflash();
            }

            return route('login');
        });
        $middleware->redirectUsersTo(function (Request $request): string {
            if ($request->hasSession()) {
                $request->session()->reflash();
            }

            return route('dashboard');
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request): bool => ResponseFormat::wantsJson($request));

        $exceptions->respond(function (Response $response, Throwable $exception, Request $request): Response {
            // Failed authorisation attempts are part of the audit trail. A
            // record that cannot be written must not change the answer.
            if ($response->getStatusCode() === 403) {
                rescue(fn () => app(AuditLogger::class)->record('auth.access.denied', AuditOutcome::Blocked, $request->user(), [
                    'method' => $request->method(),
                    'route' => $request->route()?->getName(),
                ]), report: true);
            }

            if (ResponseFormat::wantsJson($request)) {
                return $response;
            }

            $status = $response->getStatusCode();

            // An expired CSRF token. If the user is still signed in (for example
            // they signed in again in another tab), send them back to try
            // again; otherwise the session timed out.
            if ($status === 419) {
                return $request->user() !== null
                    ? back()->with('error', 'This page had expired, so your last action was not saved. Please try again.')
                    : redirect()->route('login')->with('status', 'Your session expired. Please sign in again.');
            }

            // Only for an action: the page it goes back to is under the same
            // limit, so a refused page would keep redirecting to itself.
            if ($status === 429 && $request->header('X-Inertia') && ! $request->isMethodSafe()) {
                return back()->with('error', 'Too many attempts. Please wait a minute and try again.');
            }

            if (! app()->hasDebugModeEnabled() && in_array($status, [403, 404, 429, 500, 503], true)) {
                // If the error page itself cannot be built (for example no
                // built assets, mid-deployment), the framework's answer stands.
                try {
                    $errorPage = Inertia::render('Error', [
                        'status' => $status,
                        'productName' => config('crms.product.short_name'),
                    ])
                        ->toResponse($request)
                        ->setStatusCode($status);
                } catch (Throwable $renderFailure) {
                    report($renderFailure);

                    return $response;
                }

                // Such as Retry-After, X-RateLimit-* and Refresh.
                if ($exception instanceof HttpExceptionInterface) {
                    $errorPage->headers->add($exception->getHeaders());
                }

                return $errorPage;
            }

            return $response;
        });
    })->create();
