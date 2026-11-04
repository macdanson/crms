<?php

namespace App\Http\Middleware;

use App\Http\ResponseFormat;
use Closure;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Replaces the framework's `password.confirm` middleware.
 *
 * After confirming, the framework sends the browser back to the page an
 * action (such as DELETE /settings/security/sessions/{id}) was taken from,
 * taking that page from the Referer header as given. This middleware only
 * returns to a page on this site.
 */
class RequirePasswordConfirmation extends RequirePassword
{
    /**
     * The framework passes auth.password_timeout only to its own class, so
     * read it here; otherwise the 3-hour default would apply.
     */
    public function __construct(ResponseFactory $responseFactory, UrlGenerator $urlGenerator, Repository $config)
    {
        parent::__construct($responseFactory, $urlGenerator, $config->get('auth.password_timeout'));
    }

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

        if ($request->isMethodSafe() || ResponseFormat::wantsJson($request) || ! $this->shouldConfirmPassword($request, $timeout)) {
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
