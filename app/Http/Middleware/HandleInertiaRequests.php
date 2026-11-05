<?php

namespace App\Http\Middleware;

use App\Enums\Permission;
use App\Models\User;
use App\Services\SessionPolicy;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Middleware;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Handle the incoming Inertia request.
     *
     * Pages keep their props in the browser's history (encrypted; see
     * inertia.history.encrypt). However a session ended (sign-out, timeout,
     * security change), the browser ends up on a guest page, which makes the
     * earlier pages unreadable, so Back cannot show them to the next person.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() === null && $request->hasSession()) {
            Inertia::clearHistory();
        }

        return parent::handle($request, $next);
    }

    /**
     * Define the props that are shared by default.
     *
     * Only the minimum the shell needs about the user is shared; the full
     * user model (and its hidden secrets) is never serialised to the browser.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $session = $request->hasSession() ? $request->session() : null;

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'product' => config('crms.product'),
            'auth' => [
                'user' => $user instanceof User ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'job_title' => $user->job_title,
                    'staff_number' => $user->staff_number,
                    'initials' => $user->initials(),
                    'two_factor_enabled' => $user->hasEnabledTwoFactorAuthentication(),
                    // Only to show the right menu items; every action is
                    // authorised again on the server.
                    'permissions' => array_map(fn (Permission $permission): string => $permission->value, $user->permissions()),
                    'unread_notifications' => $user->unreadNotifications()->count(),
                ] : null,
            ],
            'session' => $user instanceof User && $session !== null ? [
                'idle_timeout' => app(SessionPolicy::class)->idleTimeout(),
                'idle_warning' => (int) config('crms.auth.idle_warning'),
                'absolute_remaining' => app(SessionPolicy::class)->absoluteRemaining($session),
                'absolute_warning' => (int) config('crms.auth.absolute_warning'),
            ] : null,
            'flash' => [
                'status' => $this->statusMessage($session?->get('status')),
                'error' => $session?->get('error'),
            ],
        ];
    }

    /**
     * Fortify flashes machine-readable status codes; users see plain
     * language instead. Codes whose outcome the page already shows are hidden.
     */
    private function statusMessage(mixed $status): ?string
    {
        if (! is_string($status)) {
            return null;
        }

        return match ($status) {
            Fortify::PASSWORD_UPDATED => 'Your password has been changed. Other sessions have been signed out.',
            Fortify::RECOVERY_CODES_GENERATED => 'New recovery codes generated. Your old codes no longer work.',
            Fortify::TWO_FACTOR_AUTHENTICATION_CONFIRMED,
            Fortify::TWO_FACTOR_AUTHENTICATION_ENABLED,
            Fortify::TWO_FACTOR_AUTHENTICATION_DISABLED => null,
            default => $status,
        };
    }
}
