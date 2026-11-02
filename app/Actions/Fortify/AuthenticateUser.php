<?php

namespace App\Actions\Fortify;

use App\Enums\AccountStatus;
use App\Enums\AuditOutcome;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Timebox;
use Illuminate\Validation\ValidationException;

/**
 * Verifies user credentials for Fortify's login pipeline.
 *
 * Unknown emails and wrong passwords take the same time and return the same
 * generic error. Only once the password is proven correct do we reveal that
 * an account is suspended, deactivated or not yet activated.
 */
class AuthenticateUser
{
    /**
     * Minimum duration of a credential check, in microseconds.
     */
    private const MINIMUM_DURATION = 250_000;

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

            if ($user === null || ! Hash::check($password, $user->password)) {
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

        if (Hash::needsRehash($user->password)) {
            $user->forceFill(['password' => $password])->save();
        }

        return $user;
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
