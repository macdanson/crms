<?php

namespace App\Actions\Fortify;

use App\Enums\AccountStatus;
use App\Models\User;
use App\Services\SessionPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    public function __construct(private SessionPolicy $policy) {}

    /**
     * Validate and reset the user's forgotten password.
     *
     * A reset proves control of the email address, so a pending account whose
     * invitation expired becomes active. Suspended accounts stay suspended.
     * Every existing session is signed out.
     *
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function reset(User $user, array $input): void
    {
        Validator::make($input, [
            'password' => $this->passwordRules(),
        ])->validate();

        $attributes = [
            'password' => Hash::make($input['password']),
            'password_changed_at' => now(),
        ];

        if ($user->isPending()) {
            $attributes['status'] = AccountStatus::Active;
            $attributes['email_verified_at'] = $user->email_verified_at ?? now();
        }

        DB::transaction(function () use ($user, $attributes): void {
            $user->forceFill($attributes)->save();

            $this->policy->endOtherSessions($user);
        });
    }
}
