<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Replaces the framework's `password.confirm` middleware.
 *
 * The framework remembers the URL that needed confirmation and sends the
 * browser back to it with GET afterwards. That fails for actions such as
 * DELETE /settings/security/sessions/{id}. For those, this middleware
 * remembers the page the action was taken from instead, so the user lands
 * back on it after confirming and can repeat the action.
 */
class RequirePasswordConfirmation extends RequirePassword
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     * @param  string|null  $redirectToRoute
     * @param  string|int|null  $passwordTimeoutSeconds
     */
    public function handle($request, Closure $next, $redirectToRoute = null, $passwordTimeoutSeconds = null): Response
    {
        $timeout = $passwordTimeoutSeconds === null ? null : (int) $passwordTimeoutSeconds;

        if ($request->isMethodSafe() || $request->expectsJson() || ! $this->shouldConfirmPassword($request, $timeout)) {
            return parent::handle($request, $next, $redirectToRoute, $passwordTimeoutSeconds);
        }

        $request->session()->put('url.intended', $this->originatingPage());

        return $this->responseFactory->redirectToRoute($redirectToRoute ?: 'password.confirm');
    }

    /**
     * The same-site page the action was submitted from, or the dashboard.
     */
    private function originatingPage(): string
    {
        $previous = $this->urlGenerator->previous();
        $root = rtrim($this->urlGenerator->to('/'), '/');

        return $previous === $root || Str::startsWith($previous, $root.'/')
            ? $previous
            : $this->urlGenerator->route('dashboard');
    }
}
