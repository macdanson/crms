<?php

use App\Enums\AccountStatus;
use App\Models\AuditEvent;
use App\Models\User;
use App\Notifications\AccountInvitation;
use App\Services\AccountLifecycle;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
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

    $this->travel(config('auth.passwords.invitations.expire') + 1)->minutes();

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

it('states the activation link expiry that the token broker enforces', function () {
    config(['auth.passwords.invitations.expire' => 120]);
    [$user, $token] = inviteUser();

    $mail = (new AccountInvitation($token))->toMail($user);

    expect(implode(' ', $mail->outroLines))->toContain('expires in 2 hours');
});

it('activates the account only once when a second request uses the link before the first has finished', function () {
    [$user, $token] = inviteUser();
    $lifecycle = app(AccountLifecycle::class);
    $secondRequestStarted = false;
    $secondRequestActivated = null;

    // Models the interleaving on one connection: the second request runs
    // after the first has found the link valid, before it activates the
    // account. Across connections the same guarantee comes from the
    // conditional update in AccountLifecycle::claimPendingAccount.
    DB::listen(function (QueryExecuted $query) use (&$secondRequestStarted, &$secondRequestActivated, $lifecycle, $user, $token): void {
        if ($secondRequestStarted || ! str_contains($query->sql, 'account_activation_tokens')) {
            return;
        }

        $secondRequestStarted = true;
        $secondRequestActivated = $lifecycle->activate($user->email, $token, 'Second-Password-2026!');
    });

    $firstRequestActivated = $lifecycle->activate($user->email, $token, 'First-Password-2026!');

    expect([$firstRequestActivated, $secondRequestActivated])->toBe([false, true])
        ->and(Hash::check('Second-Password-2026!', $user->fresh()->password))->toBeTrue()
        ->and(AuditEvent::where('event', 'account.activated')->count())->toBe(1);
});

it('refuses a superseded link when the account is invited again while it is being used', function () {
    [$user, $oldToken] = inviteUser();
    $lifecycle = app(AccountLifecycle::class);
    $reinvited = false;

    // An administrator re-sends the invitation after the old link was found
    // valid, before it activates the account.
    DB::listen(function (QueryExecuted $query) use (&$reinvited, $lifecycle, $user): void {
        if ($reinvited || ! str_contains($query->sql, 'account_activation_tokens')) {
            return;
        }

        $reinvited = true;
        $lifecycle->invite(['name' => $user->name, 'email' => $user->email]);
    });

    expect($lifecycle->activate($user->email, $oldToken, 'Old-Link-Password-2026!'))->toBeFalse();

    $newToken = '';
    Notification::assertSentTo($user, AccountInvitation::class, function (AccountInvitation $notification) use (&$newToken, $oldToken): bool {
        if ($notification->token !== $oldToken) {
            $newToken = $notification->token;
        }

        return true;
    });

    expect($newToken)->not->toBe('')
        ->and($lifecycle->activate($user->email, $newToken, 'New-Link-Password-2026!'))->toBeTrue()
        ->and(Hash::check('New-Link-Password-2026!', $user->fresh()->password))->toBeTrue();
});
