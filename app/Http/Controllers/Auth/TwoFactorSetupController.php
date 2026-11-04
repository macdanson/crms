<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Fortify\EnableTwoFactorAuthentication;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SecondFactor;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;

/**
 * The mandatory authenticator enrolment screen shown after first sign-in.
 *
 * The QR code and setup key are only rendered for an unconfirmed secret.
 * Once confirmed, the page shows the recovery codes a single time.
 */
class TwoFactorSetupController extends Controller
{
    public function show(Request $request, EnableTwoFactorAuthentication $enable, SecondFactor $secondFactor): Response|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->hasEnabledTwoFactorAuthentication()) {
            if ($request->session()->pull(SecondFactor::SHOW_RECOVERY_CODES)) {
                // Codes that cannot be read are replaced, so the user always
                // leaves enrolment with codes they have seen. Two pages
                // loading at once then show the same new codes.
                if ($user->recoveryCodes() === []) {
                    $this->whileSettingUp($user, function (User $current): void {
                        if ($current->recoveryCodes() === []) {
                            app(GenerateNewRecoveryCodes::class)($current);
                        }
                    });
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

        // Reloading the page keeps the code already scanned into the user's
        // app. A new secret is made when none exists, when it can no longer
        // be read (for example after an app key change; nothing can be
        // confirmed with it), and for any other session: a secret is never
        // shown to two sessions, one of which may know only the password.
        $this->whileSettingUp($user, fn (User $current) => $enable(
            $current,
            $current->hasUnreadableTwoFactorSecret() || ! $secondFactor->holdsEnrolment($request->session(), $current),
        ));
        $user->refresh();

        return Inertia::render('auth/TwoFactorSetup', [
            'step' => 'scan',
            'qrCodeSvg' => $user->twoFactorQrCodeSvg(),
            'setupKey' => $user->twoFactorSecret(),
            'recoveryCodes' => [],
        ]);
    }

    /**
     * Run $change with the user freshly loaded (the same instance), holding
     * a lock, so two pages loading at once do not each save a different
     * secret or set of codes.
     *
     * @param  Closure(User): mixed  $change
     */
    private function whileSettingUp(User $user, Closure $change): void
    {
        Cache::lock('crms.two-factor-setup.'.$user->getKey(), 10)->block(5, function () use ($user, $change): void {
            $user->refresh();

            $change($user);
        });
    }
}
