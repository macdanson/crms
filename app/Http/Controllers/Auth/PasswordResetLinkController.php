<?php

namespace App\Http\Controllers\Auth;

use Closure;
use Illuminate\Contracts\Auth\PasswordBroker;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Support\Timebox;
use Laravel\Fortify\Http\Controllers\PasswordResetLinkController as FortifyPasswordResetLinkController;
use Laravel\Fortify\Http\Requests\SendPasswordResetLinkRequest;

/**
 * Fortify's "forgot password" endpoint, made to take the same time whether
 * or not the email address belongs to an account:
 *
 * - the reply is the same (PasswordResetLinkRequestedResponse);
 * - the email is queued (ResetPasswordLink);
 * - sending a link hashes a new token, so every other outcome (unknown
 *   email, or a link requested again too soon) hashes a stand-in;
 * - the timebox hides the remaining database work.
 */
class PasswordResetLinkController extends FortifyPasswordResetLinkController
{
    /**
     * Minimum duration of a reset link request, in microseconds.
     */
    private const MINIMUM_DURATION = 300_000;

    /**
     * The status the broker returned for this request.
     */
    private ?string $brokerStatus = null;

    public function store(SendPasswordResetLinkRequest $request): Responsable
    {
        $this->brokerStatus = null;

        return (new Timebox)->call(function () use ($request): Responsable {
            $response = parent::store($request);

            if ($this->brokerStatus !== Password::RESET_LINK_SENT) {
                Hash::make(Str::random(64));
            }

            return $response;
        }, self::MINIMUM_DURATION);
    }

    /**
     * Fortify's broker, recording the status it returns.
     */
    protected function broker(): PasswordBroker
    {
        $record = function (string $status): void {
            $this->brokerStatus = $status;
        };

        return new class(parent::broker(), $record) implements PasswordBroker
        {
            /**
             * @param  Closure(string): void  $record
             */
            public function __construct(private PasswordBroker $broker, private Closure $record) {}

            /**
             * @param  array<string, mixed>  $credentials
             */
            public function sendResetLink(array $credentials, ?Closure $callback = null): string
            {
                $status = $this->broker->sendResetLink($credentials, $callback);
                ($this->record)($status);

                return $status;
            }

            /**
             * @param  array<string, mixed>  $credentials
             */
            public function reset(array $credentials, Closure $callback): mixed
            {
                return $this->broker->reset($credentials, $callback);
            }
        };
    }
}
