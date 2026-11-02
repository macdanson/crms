<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnforceSessionTimeouts;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureTwoFactorIsEnrolled;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequirePasswordConfirmation;
use App\Http\Middleware\SetSecurityHeaders;
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
        $middleware->prepend(AssignRequestId::class);

        $middleware->web(append: [
            EnforceSessionTimeouts::class,
            EnsureAccountIsActive::class,
            SetSecurityHeaders::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'mfa' => EnsureTwoFactorIsEnrolled::class,
            'password.confirm' => RequirePasswordConfirmation::class,
        ]);

        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request): bool => $request->wantsJsonResponse());

        $exceptions->respond(function (Response $response, Throwable $exception, Request $request): Response {
            if ($request->wantsJsonResponse()) {
                return $response;
            }

            $status = $response->getStatusCode();

            // An expired CSRF token usually means the session timed out.
            if ($status === 419) {
                return redirect()->route('login')->with('status', 'Your session expired. Please sign in again.');
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
