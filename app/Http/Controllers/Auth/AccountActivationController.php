<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ActivateAccountRequest;
use App\Services\AccountLifecycle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Lets a newly provisioned user choose a password using their emailed,
 * single-use activation link. Accounts are never self-registered.
 */
class AccountActivationController extends Controller
{
    public function create(Request $request, string $token): Response
    {
        return Inertia::render('auth/ActivateAccount', [
            'token' => $token,
            'email' => is_string($request->query('email')) ? $request->query('email') : '',
        ]);
    }

    public function store(ActivateAccountRequest $request, AccountLifecycle $accounts): RedirectResponse
    {
        $activated = $accounts->activate(
            email: $request->string('email')->toString(),
            token: $request->string('token')->toString(),
            password: $request->string('password')->toString(),
        );

        if (! $activated) {
            return back()->withErrors([
                'email' => 'This activation link is invalid or has expired. Ask your administrator for a new invitation.',
            ]);
        }

        return redirect()->route('login')->with('status', 'Your account is active. Sign in to set up multi-factor authentication.');
    }
}
