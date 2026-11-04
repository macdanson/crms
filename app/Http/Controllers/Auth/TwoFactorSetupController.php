<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Fortify\EnableTwoFactorAuthentication;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use Laravel\Fortify\Fortify;

/**
 * The mandatory authenticator enrolment screen shown after first sign-in.
 *
 * The QR code and setup key are only rendered for an unconfirmed secret.
 * Once confirmed, the page shows the recovery codes a single time.
 */
class TwoFactorSetupController extends Controller
{
    public function show(Request $request, EnableTwoFactorAuthentication $enable): Response|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->hasEnabledTwoFactorAuthentication()) {
            if ($request->session()->get('status') === Fortify::TWO_FACTOR_AUTHENTICATION_CONFIRMED) {
                // Codes that cannot be read are replaced, so the user always
                // leaves enrolment with codes they have seen.
                if ($user->recoveryCodes() === []) {
                    app(GenerateNewRecoveryCodes::class)($user);
                    $user->refresh();
                }

                return Inertia::render('auth/TwoFactorSetup', [
                    'step' => 'recovery',
                    'qrCodeSvg' => null,
                    'setupKey' => null,
                    'recoveryCodes' => $user->recoveryCodes(),
                ]);
            }

            return redirect()->route('dashboard');
        }

        // Generates a secret only when none exists, so reloading the page
        // keeps the code already scanned into the user's app. The lock stops
        // two pages loading at once from each saving a different secret.
        Cache::lock('crms.two-factor-setup.'.$user->getKey(), 10)->block(5, function () use ($user, $enable): void {
            $user->refresh();

            // A secret that can no longer be read (for example after an app
            // key change) is replaced; nothing can be confirmed with it.
            $unreadable = $user->two_factor_secret !== null && $user->twoFactorSecret() === null;

            $enable($user, $unreadable);
        });
        $user->refresh();

        return Inertia::render('auth/TwoFactorSetup', [
            'step' => 'scan',
            'qrCodeSvg' => $user->twoFactorQrCodeSvg(),
            'setupKey' => $user->twoFactorSecret(),
            'recoveryCodes' => [],
        ]);
    }
}
