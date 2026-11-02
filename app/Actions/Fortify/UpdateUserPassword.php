<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;

class UpdateUserPassword implements UpdatesUserPasswords
{
    use PasswordValidationRules;

    /**
     * Validate and update the user's password.
     *
     * Other sessions are signed out by the PasswordUpdatedViaController
     * listener in RecordAuthenticationActivity.
     *
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function update(User $user, array $input): void
    {
        Validator::make($input, [
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => [...$this->passwordRules(), 'different:current_password'],
        ], [
            'current_password.current_password' => __('The provided password does not match your current password.'),
            'password.different' => __('Your new password must be different from your current password.'),
        ])->validate();

        $user->forceFill([
            'password' => Hash::make($input['password']),
            'password_changed_at' => now(),
        ])->save();

        $this->keepCurrentSessionSignedIn($user);
    }

    /**
     * The auth.session middleware signs out any session whose stored password
     * fingerprint no longer matches. Fortify's password route does not run
     * that middleware, so refresh the fingerprint here; otherwise the user
     * would be signed out of the session they just changed it from.
     */
    private function keepCurrentSessionSignedIn(User $user): void
    {
        $guard = Auth::guard('web');
        $request = request();

        if (! $request->hasSession() || ! $user->is($guard->user())) {
            return;
        }

        $request->session()->put(
            'password_hash_web',
            $guard->hashPasswordForCookie($user->getAuthPassword()),
        );
    }
}
