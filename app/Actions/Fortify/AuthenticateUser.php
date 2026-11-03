<?php

namespace App\Actions\Fortify;

use App\Enums\AccountStatus;
use App\Enums\AuditOutcome;
use App\Models\User;
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
 * costs as much as a real password check whatever the hashing cost. Only once the password is proven correct do we reveal that
 * an account is suspended, deactivated or not yet activated.
 */
class AuthenticateUser
{
    /**
     * Minimum duration of a credential check, in microseconds.
     */
    private const MINIMUM_DURATION = 250_000;

    private const STAND_IN_HASH_KEY = 'crms.auth.stand_in_hash';

    public function __construct(private AuditLogger $audit) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(Request $request): ?User
    {
        $email = Str::lower(trim((string) $request->input('email')));
        $password = (string) $request->input('password');

        /** @var User|null $user */
        $user = (new Timebox)->call(function (Timebox $timebox) use ($email, $password): ?User {
            $user = User::query()->where('email', $email)->first();

            $passwordMatches = Hash::check($password, $user === null ? $this->standInHash() : $user->password);

            if ($user === null || ! $passwordMatches) {
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
                'email' => $this->inactiveMessage($user->status),
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

    private function inactiveMessage(AccountStatus $status): string
    {
        $contact = config('crms.product.support_contact');

        return match ($status) {
            AccountStatus::Pending => 'This account has not been activated. Use the activation link sent to your email address.',
            AccountStatus::Suspended => "This account is suspended. Contact {$contact}.",
            default => "This account is no longer active. Contact {$contact}.",
        };
    }
}
