<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AccountLifecycle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Replaces the officer's authenticator (e.g. a new phone). The old secret is
 * discarded and the officer is taken straight to re-enrolment.
 */
class TwoFactorResetController extends Controller
{
    public function __invoke(Request $request, AccountLifecycle $accounts): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $accounts->resetTwoFactor($user, actor: $user);

        return redirect()->route('two-factor.setup');
    }
}
