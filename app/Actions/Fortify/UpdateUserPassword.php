<?php

namespace App\Actions\Fortify;

use App\Models\User;
use App\Services\PasswordCheck;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
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
            'current_password' => ['required', 'string'],
            'password' => [...$this->passwordRules(), 'different:current_password'],
        ], [
            'password.different' => __('Your new password must be different from your current password.'),
        ])->after(function (ValidatorContract $validator) use ($user, $input): void {
            if ($validator->errors()->has('current_password')) {
                return;
            }

            // Limited and audited like every in-session password check.
            $error = app(PasswordCheck::class)->check(
                $user,
                (string) $input['current_password'],
                'password_change',
                __('The provided password does not match your current password.'),
            );

            if ($error !== null) {
                $validator->errors()->add('current_password', $error);
            }
        })->validate();

        $user->forceFill([
            'password' => Hash::make($input['password']),
            'password_changed_at' => now(),
        ])->save();
    }
}
