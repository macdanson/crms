<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The officer's landing page after sign-in.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('Dashboard', [
            'security' => [
                'mfa_enabled' => $user->hasEnabledTwoFactorAuthentication(),
                'recovery_codes_remaining' => $user->hasEnabledTwoFactorAuthentication() ? count($user->recoveryCodes()) : 0,
                'password_changed_at' => $user->password_changed_at?->toIso8601String(),
                'last_login_at' => $user->last_login_at?->toIso8601String(),
                'last_login_ip' => $user->last_login_ip,
            ],
        ]);
    }
}
