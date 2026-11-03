<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnforceSessionTimeouts;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureSessionIsCurrent;
use App\Http\Middleware\EnsureTwoFactorIsEnrolled;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequirePasswordConfirmation;
use App\Http\Middleware\RequireSecondFactorConfirmation;
use App\Http\Middleware\SetSecurityHeaders;
use App\Http\ResponseFormat;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Redirects after a PUT/PATCH/DELETE: inertia-laravel registers its
        // EnsureGetOnRedirect as global middleware, outside everything below,
        // so every 302 answering a non-GET Inertia request becomes a 303 and
        // is followed with GET. That covers redirects from any middleware
        // and from the exception handler, so none of them set 303 by hand.

        // Appended so they run after TrustProxies, which they rely on. Being
        // global, the security headers also cover responses that a later
        // middleware returns early (session ended, CSRF expired, 404).
        $middleware->append([AssignRequestId::class, SetSecurityHeaders::class]);

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

            if ($status === 429 && $request->header('X-Inertia')) {
                return back()->with('error', 'Too many attempts. Please wait a minute and try again.');
            }

            if (! app()->hasDebugModeEnabled() && in_array($status, [403, 404, 429, 500, 503], true)) {
                return Inertia::render('Error', [
                    'status' => $status,
                    'productName' => config('crms.product.short_name'),
                ])
                    ->toResponse($request)
                    ->setStatusCode($status);
            }

            return $response;
        });
    })->create();
