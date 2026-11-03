<?php

namespace App\Actions\Fortify;

use App\Enums\AuditOutcome;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\PasswordCheck;
use App\Services\SessionPolicy;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Support\Facades\DB;
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
     * Other sessions are signed out in the same transaction, so none of them
     * outlives the old password.
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

        $revoked = DB::transaction(function () use ($user, $input): int {
            $user->forceFill([
                'password' => Hash::make($input['password']),
                'password_changed_at' => now(),
            ])->save();

            return app(SessionPolicy::class)->endOtherSessions($user, request()->hasSession() ? request()->session() : null);
        });

        app(AuditLogger::class)->record('auth.password.changed', AuditOutcome::Success, $user, [
            'sessions_revoked' => $revoked,
        ]);
    }
}
