<?php

use App\Enums\AccountStatus;
use App\Models\AuditEvent;
use App\Models\User;
use App\Notifications\AccountInvitation;
use App\Services\AccountLifecycle;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;

/**
 * Invites a user and returns the emailed activation token.
 *
 * @return array{0: User, 1: string}
 */
function inviteUser(string $email = 'new.user@crms.test'): array
{
    Notification::fake();

    $user = app(AccountLifecycle::class)->invite(['name' => 'New User', 'email' => $email]);

    $token = '';
    Notification::assertSentTo($user, AccountInvitation::class, function (AccountInvitation $notification) use (&$token): bool {
        $token = $notification->token;

        return true;
    });

    return [$user, $token];
}

it('provisions a pending account and emails a single-use activation link', function () {
    [$user] = inviteUser();

    expect($user->status)->toBe(AccountStatus::Pending)
        ->and(AuditEvent::where('event', 'account.invited')->where('user_id', $user->id)->exists())->toBeTrue();
});

it('renders the activation page with the invited email address', function () {
    [$user, $token] = inviteUser();

    $this->get(route('activation.create', ['token' => $token, 'email' => $user->email]))
        ->assertOk()
        ->assertHeader('Cache-Control', 'must-revalidate, no-cache, no-store, private')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('auth/ActivateAccount')
            ->where('email', $user->email));
});

it('activates the account with a valid token and strong password', function () {
    [$user, $token] = inviteUser();

    $this->post(route('activation.store'), [
        'token' => $token,
        'email' => $user->email,
        'password' => 'Activation-Pass-2026!',
        'password_confirmation' => 'Activation-Pass-2026!',
    ])->assertRedirect(route('login'));

    $user->refresh();
    expect($user->status)->toBe(AccountStatus::Active)
        ->and($user->email_verified_at)->not->toBeNull()
        ->and(Hash::check('Activation-Pass-2026!', $user->password))->toBeTrue();
});

it('does not accept an activation link twice', function () {
    [$user, $token] = inviteUser();
    $payload = [
        'token' => $token,
        'email' => $user->email,
        'password' => 'Activation-Pass-2026!',
        'password_confirmation' => 'Activation-Pass-2026!',
    ];

    $this->post(route('activation.store'), $payload);
    $this->post(route('activation.store'), [...$payload, 'password' => 'Another-Pass-2026!', 'password_confirmation' => 'Another-Pass-2026!'])
        ->assertSessionHasErrors(['email' => 'This activation link is invalid or has expired. Ask your administrator for a new invitation.']);

    expect(Hash::check('Activation-Pass-2026!', $user->fresh()->password))->toBeTrue();
});

it('rejects an expired activation link', function () {
    [$user, $token] = inviteUser();

    $this->travel(config('crms.auth.activation_expire') + 1)->minutes();

    $this->post(route('activation.store'), [
        'token' => $token,
        'email' => $user->email,
        'password' => 'Activation-Pass-2026!',
        'password_confirmation' => 'Activation-Pass-2026!',
    ])->assertSessionHasErrors('email');

    expect($user->fresh()->status)->toBe(AccountStatus::Pending);
});

it('rejects a weak password during activation', function () {
    [$user, $token] = inviteUser();

    $this->post(route('activation.store'), [
        'token' => $token,
        'email' => $user->email,
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasErrors('password');

    expect($user->fresh()->status)->toBe(AccountStatus::Pending);
});

it('refuses to re-invite an account that is already active', function () {
    $user = User::factory()->create();

    app(AccountLifecycle::class)->invite(['name' => $user->name, 'email' => $user->email]);
})->throws(ValidationException::class, 'An account with this email address has already been activated.');

it('keeps the activation link working after someone requests a password reset', function () {
    [$user, $token] = inviteUser();

    // Past the reset broker's one-minute throttle, so a reset link is issued.
    $this->travel(2)->minutes();
    $this->post(route('password.email'), ['email' => $user->email]);

    $this->post(route('activation.store'), [
        'token' => $token,
        'email' => $user->email,
        'password' => 'Activation-Pass-2026!',
        'password_confirmation' => 'Activation-Pass-2026!',
    ])->assertSessionHasNoErrors();

    expect($user->fresh()->status)->toBe(AccountStatus::Active);
});

it('does not accept a password reset token as an activation link', function () {
    [$user] = inviteUser();
    $resetToken = Password::broker()->createToken($user);

    $this->post(route('activation.store'), [
        'token' => $resetToken,
        'email' => $user->email,
        'password' => 'Activation-Pass-2026!',
        'password_confirmation' => 'Activation-Pass-2026!',
    ])->assertSessionHasErrors('email');

    expect($user->fresh()->status)->toBe(AccountStatus::Pending);
});
