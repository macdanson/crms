<?php

use App\Enums\AccountStatus;
use App\Http\Responses\PasswordResetLinkRequestedResponse;
use App\Models\User;
use App\Services\SessionPolicy;
use Database\Factories\UserFactory;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

it('sends a reset link to a known email address', function () {
    Notification::fake();
    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email])
        ->assertSessionHas('status', PasswordResetLinkRequestedResponse::message())
        ->assertSessionHasNoErrors();

    Notification::assertSentTo($user, ResetPassword::class);
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
