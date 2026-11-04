<?php

namespace App\Actions\Fortify;

use App\Enums\AttemptResult;
use App\Enums\AuditOutcome;
use App\Models\User;
use App\Notifications\SignInPasswordsLocked;
use App\Services\AccountAttemptLimiter;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Timebox;
use Illuminate\Validation\ValidationException;

/**
 * Verifies user credentials for Fortify's login pipeline.
 *
 * Unknown emails and wrong passwords take the same time and return the same
 * generic error: an unknown email is checked against a stand-in hash, so it
 * costs as much as a real password check whatever the hashing cost. Only once
 * the password is proven correct do we reveal that an account is suspended,
 * deactivated or not yet activated.
 *
 * Fortify limits attempts per typed email and address. Wrong passwords are
 * also limited per account, from any address (credential stuffing). A paused
 * account answers like a wrong password, so the pause does not reveal that
 * the account exists; the owner is told by email.
 */
class AuthenticateUser
{
    /**
     * Minimum duration of a credential check, in microseconds.
     */
    private const MINIMUM_DURATION = 250_000;

    private const STAND_IN_HASH_KEY = 'crms.auth.stand_in_hash';

    public function __construct(
        private AuditLogger $audit,
        private AccountAttemptLimiter $limiter,
    ) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(Request $request): ?User
    {
        $email = (string) $request->input('email');
        $password = (string) $request->input('password');

        /** @var User|null $user */
        $user = (new Timebox)->call(function (Timebox $timebox) use ($email, $password): ?User {
            $user = User::findByEmail($email);

            if ($user === null) {
                Hash::check($password, $this->standInHash());

                return null;
            }

            $result = $this->limiter->attempt(
                AccountAttemptLimiter::SIGN_IN_PASSWORD,
                $user,
                fn (): bool => Hash::check($password, $user->password),
                onLock: fn () => $this->alert($user),
            );

            if ($result === AttemptResult::Locked) {
                Hash::check($password, $this->standInHash());

                $this->audit->record('auth.lockout', AuditOutcome::Blocked, $user, ['scope' => 'account']);
            }

            if ($result !== AttemptResult::Passed) {
                return null;
            }

            $timebox->returnEarly();

            return $user;
        }, self::MINIMUM_DURATION);

        if ($user === null) {
            return null;
        }

        if (! $user->isActive()) {
            $this->audit->record('auth.login.blocked', AuditOutcome::Blocked, $user, [
                'status' => $user->status->value,
            ]);

            throw ValidationException::withMessages([
                'email' => $user->status->signInBlockedMessage(),
            ]);
        }

        // Other sessions are tracked by their session stamp, not the password
        // hash, so rehashing does not sign them out.
        if (Hash::needsRehash($user->password)) {
            $user->forceFill(['password' => $password])->save();
        }

        return $user;
    }

    /**
     * Sending the alert must never turn a refused sign-in into an error; a
     * failure is reported instead.
     */
    private function alert(User $user): void
    {
        rescue(fn () => $user->notify(new SignInPasswordsLocked($this->limiter->unlocksAt(AccountAttemptLimiter::SIGN_IN_PASSWORD, $user))), report: true);
    }

    /**
     * A hash of a random secret, made with the current hashing settings, to
     * check unknown emails against.
     */
    private function standInHash(): string
    {
        $hash = Cache::get(self::STAND_IN_HASH_KEY);

        if (! is_string($hash) || Hash::needsRehash($hash)) {
            $hash = Hash::make(Str::random(40));
            Cache::forever(self::STAND_IN_HASH_KEY, $hash);
        }

        return $hash;
    }
}
