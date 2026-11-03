<?php

use App\Enums\AccountStatus;
use App\Http\Responses\PasswordResetLinkRequestedResponse;
use App\Models\User;
use App\Notifications\ResetPasswordLink;
use App\Services\SessionPolicy;
use Database\Factories\UserFactory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;

it('sends a reset link to a known email address', function () {
    Notification::fake();
    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email])
        ->assertSessionHas('status', PasswordResetLinkRequestedResponse::message())
        ->assertSessionHasNoErrors();

    Notification::assertSentTo($user, ResetPasswordLink::class);
});

it('gives an unknown email address the same response so accounts cannot be discovered', function () {
    Notification::fake();

    $this->post(route('password.email'), ['email' => 'nobody@crms.test'])
        ->assertSessionHas('status', PasswordResetLinkRequestedResponse::message())
        ->assertSessionHasNoErrors();

    Notification::assertNothingSent();
});

it('resets the password with a valid token and enforces the password policy', function () {
    $user = User::factory()->create();
    $token = Password::broker()->createToken($user);

    $this->post(route('password.update'), [
        'token' => $token,
        'email' => $user->email,
        'password' => 'short',
        'password_confirmation' => 'short',
    ])->assertSessionHasErrors('password');

    $this->post(route('password.update'), [
        'token' => $token,
        'email' => $user->email,
        'password' => 'A-Brand-New-Pass-42',
        'password_confirmation' => 'A-Brand-New-Pass-42',
    ])->assertRedirect(route('login'))->assertSessionHasNoErrors();

    expect(Hash::check('A-Brand-New-Pass-42', $user->fresh()->password))->toBeTrue();
});

it('rejects an invalid reset token', function () {
    $user = User::factory()->create();

    $this->post(route('password.update'), [
        'token' => 'not-a-real-token',
        'email' => $user->email,
        'password' => 'A-Brand-New-Pass-42',
        'password_confirmation' => 'A-Brand-New-Pass-42',
    ])->assertSessionHasErrors('email');

    expect(Hash::check(UserFactory::PASSWORD, $user->fresh()->password))->toBeTrue();
});

it('activates a pending account whose owner resets the password', function () {
    $user = User::factory()->pending()->create();

    $this->post(route('password.update'), [
        'token' => Password::broker()->createToken($user),
        'email' => $user->email,
        'password' => 'A-Brand-New-Pass-42',
        'password_confirmation' => 'A-Brand-New-Pass-42',
    ]);

    expect($user->fresh()->status)->toBe(AccountStatus::Active)
        ->and($user->fresh()->email_verified_at)->not->toBeNull();
});

it('keeps a suspended account suspended after a password reset', function () {
    $user = User::factory()->suspended()->create();

    $this->post(route('password.update'), [
        'token' => Password::broker()->createToken($user),
        'email' => $user->email,
        'password' => 'A-Brand-New-Pass-42',
        'password_confirmation' => 'A-Brand-New-Pass-42',
    ]);

    expect($user->fresh()->status)->toBe(AccountStatus::Suspended);
});

it('states the reset link expiry that the token broker enforces', function () {
    config(['auth.passwords.users.expire' => 30]);

    expect(PasswordResetLinkRequestedResponse::message())->toContain('expires in 30 minutes');
});

it('signs out every session with any session driver after a password reset', function () {
    $user = User::factory()->withTwoFactor()->create();
    $signedInStamp = app(SessionPolicy::class)->stampFor($user);

    $this->post(route('password.update'), [
        'token' => Password::broker()->createToken($user),
        'email' => $user->email,
        'password' => 'A-Brand-New-Pass-42',
        'password_confirmation' => 'A-Brand-New-Pass-42',
    ])->assertRedirect(route('login'));

    $this->actingAs($user->fresh())
        ->withSession([SessionPolicy::STAMP => $signedInStamp])
        ->get(route('dashboard'))
        ->assertRedirect(route('login'));
});

it('takes at least the same minimum time for an unknown email address', function () {
    // Warm up first, so start-up time is not what is measured.
    $this->post(route('password.email'), ['email' => 'someone@crms.test']);

    $startedAt = microtime(true);

    $this->post(route('password.email'), ['email' => 'nobody@crms.test'])->assertSessionHasNoErrors();

    expect(microtime(true) - $startedAt)->toBeGreaterThanOrEqual(0.3);
});

it('encrypts the queued reset email, which holds a working token', function () {
    config(['queue.default' => 'database']);
    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email])->assertSessionHasNoErrors();

    $command = json_decode((string) DB::table('jobs')->value('payload'), true)['data']['command'];

    expect($command)->not->toContain('token')
        ->and(Crypt::decryptString($command))->toContain('token');
});

it('hashes once for an unknown email address, as the broker does for an account', function () {
    $hasher = Mockery::mock(app('hash'));
    $hasher->shouldReceive('make')->once()->passthru();
    Hash::swap($hasher);

    $this->post(route('password.email'), ['email' => 'nobody@crms.test'])->assertSessionHasNoErrors();
});

it('hashes for a known email whose last link is too recent to send another', function () {
    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email])->assertSessionHasNoErrors();

    $hasher = Mockery::mock(app('hash'));
    $hasher->shouldReceive('make')->once()->passthru();
    Hash::swap($hasher);

    $this->post(route('password.email'), ['email' => $user->email])->assertSessionHasNoErrors();
});

it('hashes only the token when a link is sent, whatever response class is bound', function () {
    app()->bind(SuccessfulPasswordResetLinkRequestResponse::class, fn () => new class implements SuccessfulPasswordResetLinkRequestResponse
    {
        public function toResponse($request): RedirectResponse
        {
            return back();
        }
    });
    $user = User::factory()->create();

    $hasher = Mockery::mock(app('hash'));
    $hasher->shouldReceive('make')->once()->passthru();
    Hash::swap($hasher);

    $this->post(route('password.email'), ['email' => $user->email]);
});

it('keeps an account suspended while its pending owner was resetting the password', function () {
    $user = User::factory()->pending()->create();
    $token = Password::broker()->createToken($user);

    // An administrator suspends the account while the new password is being
    // saved (once: Fortify saves the user again afterwards).
    $suspended = false;
    User::updating(function (User $saving) use ($user, &$suspended): void {
        if (! $suspended && $saving->is($user)) {
            $suspended = true;
            DB::table('users')->where('id', $user->id)->update(['status' => AccountStatus::Suspended->value]);
        }
    });

    $this->post(route('password.update'), [
        'token' => $token,
        'email' => $user->email,
        'password' => 'A-Brand-New-Pass-42',
        'password_confirmation' => 'A-Brand-New-Pass-42',
    ]);

    expect($user->fresh()->status)->toBe(AccountStatus::Suspended);
});

it('activates an account that became pending after it was loaded for the reset', function () {
    $user = User::factory()->create();
    $token = Password::broker()->createToken($user);

    // The account is returned to pending while the new password is saved.
    $returnedToPending = false;
    User::updating(function (User $saving) use ($user, &$returnedToPending): void {
        if (! $returnedToPending && $saving->is($user)) {
            $returnedToPending = true;
            DB::table('users')->where('id', $user->id)->update(['status' => AccountStatus::Pending->value]);
        }
    });

    $this->post(route('password.update'), [
        'token' => $token,
        'email' => $user->email,
        'password' => 'A-Brand-New-Pass-42',
        'password_confirmation' => 'A-Brand-New-Pass-42',
    ]);

    expect($returnedToPending)->toBeTrue()
        ->and($user->fresh()->status)->toBe(AccountStatus::Active);
});
