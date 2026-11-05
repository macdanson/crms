<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AccountStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SuspendUserRequest;
use App\Models\User;
use App\Services\AccountLifecycle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Suspending, deactivating and restoring accounts, and resetting a lost
 * authenticator. All of it is done by another user, never to oneself.
 */
class UserLifecycleController extends Controller
{
    public function suspend(SuspendUserRequest $request, User $user, AccountLifecycle $accounts): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $accounts->suspend($user, $request->string('reason')->toString(), $actor);

        return back()->with('status', "{$user->name} suspended and signed out.");
    }

    public function deactivate(SuspendUserRequest $request, User $user, AccountLifecycle $accounts): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $accounts->deactivate($user, $request->string('reason')->toString(), $actor);

        return back()->with('status', "{$user->name} deactivated. Their roles have been revoked.");
    }

    public function reactivate(Request $request, User $user, AccountLifecycle $accounts): RedirectResponse
    {
        Gate::authorize('changeStatus', $user);

        if (! in_array($user->status, [AccountStatus::Suspended, AccountStatus::Deactivated], true)) {
            return back()->with('error', 'Only a suspended or deactivated account can be restored.');
        }

        /** @var User $actor */
        $actor = $request->user();

        $status = $accounts->reactivate($user, $actor);

        return back()->with('status', "Account restored: {$status->label()}.");
    }

    public function resetTwoFactor(Request $request, User $user, AccountLifecycle $accounts): RedirectResponse
    {
        Gate::authorize('resetTwoFactor', $user);

        /** @var User $actor */
        $actor = $request->user();

        $accounts->resetTwoFactor($user, $actor, proof: 'administrator');

        return back()->with('status', "Authenticator cleared. {$user->name} must enrol again at next sign-in.");
    }

    public function resendInvitation(Request $request, User $user, AccountLifecycle $accounts): RedirectResponse
    {
        Gate::authorize('changeStatus', $user);

        if (! $user->isPending()) {
            return back()->with('error', 'This account has already been activated.');
        }

        /** @var User $actor */
        $actor = $request->user();

        $accounts->invite(['name' => $user->name, 'email' => $user->email], $actor);

        return back()->with('status', "A new activation link has been sent to {$user->email}.");
    }
}
