<?php

namespace App\Http\Middleware;

use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Middleware;
use Laravel\Fortify\Fortify;

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
                ] : null,
            ],
            'session' => $user instanceof User ? [
                'idle_timeout' => (int) config('crms.auth.idle_timeout') * 60,
                'idle_warning' => (int) config('crms.auth.idle_warning'),
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
