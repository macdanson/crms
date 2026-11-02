<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Fortify\EnableTwoFactorAuthentication;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
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
        // keeps the code already scanned into the user's app.
        $enable($user);
        $user->refresh();

        return Inertia::render('auth/TwoFactorSetup', [
            'step' => 'scan',
            'qrCodeSvg' => $user->twoFactorQrCodeSvg(),
            'setupKey' => Fortify::currentEncrypter()->decrypt($user->two_factor_secret),
            'recoveryCodes' => [],
        ]);
    }
}
