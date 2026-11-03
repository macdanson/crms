<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ResetTwoFactorRequest;
use App\Models\User;
use App\Services\AccountLifecycle;
use Illuminate\Http\RedirectResponse;

/**
 * Replaces the user's authenticator (e.g. a new phone). The user proves the
 * current second factor first. The old secret is discarded, every other
 * session is signed out and the user is taken straight to re-enrolment.
 */
class TwoFactorResetController extends Controller
{
    public function __invoke(ResetTwoFactorRequest $request, AccountLifecycle $accounts): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $request->proveSecondFactor();

        $accounts->resetTwoFactor($user, actor: $user, keepSession: $request->session());

        return redirect()->route('two-factor.setup');
    }
}
