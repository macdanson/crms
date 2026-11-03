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
     * A reset proves control of the email address, so a pending account
     * becomes active. Decided in one conditional update on the stored status
     * (for an account that is not pending it changes no row), so a suspension
     * made meanwhile is never undone. The account is then reloaded either
     * way, so nothing later works with a status that has changed.
     */
    private function activateIfStillPending(User $user): void
    {
        User::query()
            ->whereKey($user->getKey())
            ->where('status', AccountStatus::Pending)
            ->update([
                'status' => AccountStatus::Active,
                'email_verified_at' => $user->email_verified_at ?? now(),
                'updated_at' => now(),
            ]);

        $user->refresh();
    }

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

        DB::transaction(function () use ($user, $input): void {
            $user->forceFill([
                'password' => Hash::make($input['password']),
                'password_changed_at' => now(),
            ])->save();

            $this->activateIfStillPending($user);

            $this->policy->endOtherSessions($user);
        });
    }
}
