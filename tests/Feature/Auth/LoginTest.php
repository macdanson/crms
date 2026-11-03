<?php

use App\Actions\Fortify\AuthenticateUser;
use App\Enums\AuditOutcome;
use App\Models\AuditEvent;
use App\Models\User;
use App\Notifications\NewDeviceSignIn;
use Database\Factories\UserFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;

it('renders the sign-in page for guests', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('auth/Login'));
});

it('redirects guests from the dashboard to the sign-in page', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

it('does not offer public registration', function () {
    $this->get('/register')->assertNotFound();
    $this->post('/register', [])->assertNotFound();
});

it('signs in an active user and records the sign-in', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => UserFactory::PASSWORD,
    ])->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);

    expect($user->fresh()->last_login_at)->not->toBeNull()
        ->and(AuditEvent::where('event', 'auth.login')->where('user_id', $user->id)->count())->toBe(1);
});

it('matches the email address case-insensitively', function () {
    $user = User::factory()->create(['email' => 'user@crms.test']);

    $this->post(route('login.store'), [
        'email' => 'User@CRMS.test',
        'password' => UserFactory::PASSWORD,
    ]);

    $this->assertAuthenticatedAs($user);
});

it('rejects a wrong password with a generic message and audits the failure', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'Wrong-Password-123!',
    ])->assertSessionHasErrors(['email' => trans('auth.failed')]);

    $this->assertGuest();

    $failure = AuditEvent::where('event', 'auth.login.failed')->sole();
    expect($failure->user_id)->toBe($user->id)
        ->and($failure->outcome)->toBe(AuditOutcome::Failure)
        ->and($failure->context)->toBe(['email' => $user->email]);
});

it('gives an unknown email the same message as a wrong password', function () {
    $this->post(route('login.store'), [
        'email' => 'nobody@crms.test',
        'password' => 'Wrong-Password-123!',
    ])->assertSessionHasErrors(['email' => trans('auth.failed')]);

    $this->assertGuest();
});

it('never stores the submitted password in the audit trail', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'Secret-Guess-987!',
    ]);

    expect(AuditEvent::all()->toJson())->not->toContain('Secret-Guess-987!');
});

it('refuses sign-in for accounts that are not active', function (string $state, string $message) {
    $user = User::factory()->{$state}()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => UserFactory::PASSWORD,
    ])->assertSessionHasErrors(['email' => $message]);

    $this->assertGuest();
    expect(AuditEvent::where('event', 'auth.login.blocked')->where('user_id', $user->id)->exists())->toBeTrue();
})->with([
    'pending' => ['pending', 'This account has not been activated. Use the activation link sent to your email address.'],
    'suspended' => ['suspended', 'This account is suspended. Contact your system administrator.'],
    'deactivated' => ['deactivated', 'This account is no longer active. Contact your system administrator.'],
]);

it('locks out repeated failed attempts and audits the lockout', function () {
    $user = User::factory()->create();

    foreach (range(1, 5) as $attempt) {
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'Wrong-Password-123!']);
    }

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => UserFactory::PASSWORD,
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
    expect(AuditEvent::where('event', 'auth.lockout')->exists())->toBeTrue();
});

it('sends users with a confirmed authenticator to the two-factor challenge', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => UserFactory::PASSWORD,
    ])->assertRedirect(route('two-factor.login'));

    $this->assertGuest();
});

it('alerts the user when a new device signs in', function () {
    Notification::fake();
    $user = User::factory()->create();

    $this->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0) Chrome/130.0')
        ->post(route('login.store'), ['email' => $user->email, 'password' => UserFactory::PASSWORD]);
    $this->post(route('logout'));

    $this->withHeader('User-Agent', 'Mozilla/5.0 (Linux; Android 14) Mobile Safari/537.36')
        ->post(route('login.store'), ['email' => $user->email, 'password' => UserFactory::PASSWORD]);

    Notification::assertSentToTimes($user, NewDeviceSignIn::class, 1);
});

it('does not alert on the first sign-in or a recognised device', function () {
    Notification::fake();
    $user = User::factory()->create();

    foreach (range(1, 2) as $attempt) {
        $this->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0) Chrome/130.0')
            ->post(route('login.store'), ['email' => $user->email, 'password' => UserFactory::PASSWORD]);
        $this->post(route('logout'));
    }

    Notification::assertNothingSentTo($user);
});

it('signs the user out and records it', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)
        ->post(route('logout'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', 'You have been signed out.');

    $this->assertGuest();
    expect(AuditEvent::where('event', 'auth.logout')->where('user_id', $user->id)->exists())->toBeTrue();
});

it('checks the credentials only once per sign-in', function () {
    $user = User::factory()->create();

    $this->mock(AuthenticateUser::class)
        ->shouldReceive('__invoke')
        ->once()
        ->andReturn($user);

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => UserFactory::PASSWORD,
    ]);

    $this->assertAuthenticatedAs($user);
});

it('never issues a remember-me cookie', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => UserFactory::PASSWORD,
        'remember' => '1',
    ])->assertCookieMissing(Auth::guard('web')->getRecallerName());

    $this->assertAuthenticatedAs($user);
});

it('does not carry remember-me into the two-factor challenge', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => UserFactory::PASSWORD,
        'remember' => '1',
    ])->assertSessionHas('login.remember', false);
});

it('shows the previous sign-in rather than the current one', function () {
    config(['crms.auth.require_mfa' => false]);
    $user = User::factory()->create([
        'last_login_at' => '2026-10-30 08:00:00',
        'last_login_ip' => '10.0.0.5',
    ]);

    $this->post(route('login.store'), ['email' => $user->email, 'password' => UserFactory::PASSWORD]);

    $this->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('security.previous_login.ip', '10.0.0.5')
            ->where('security.previous_login.at', fn (string $at) => str_starts_with($at, '2026-10-30T08:00:00')));
});

it('does not treat a browser update as a new device', function () {
    Notification::fake();
    $user = User::factory()->create();

    $this->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0) Chrome/130.0')
        ->post(route('login.store'), ['email' => $user->email, 'password' => UserFactory::PASSWORD]);
    $this->post(route('logout'));

    $this->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0) Chrome/131.0')
        ->post(route('login.store'), ['email' => $user->email, 'password' => UserFactory::PASSWORD]);

    Notification::assertNothingSentTo($user);
});

it('records the sign-in under the session the user goes on to use', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => UserFactory::PASSWORD]);

    // Fortify issues a new session ID during sign-in; the audit entry must
    // carry the ID the session has once sign-in is complete.
    $sessionHash = hash_hmac('sha256', session()->getId(), (string) config('app.key'));

    expect(AuditEvent::where('event', 'auth.login')->sole()->session_hash)->toBe($sessionHash);
});

it('alerts a sign-in to an account unused for longer than devices are remembered', function () {
    Notification::fake();
    $userAgent = 'Mozilla/5.0 (Windows NT 10.0) Chrome/130.0';
    $user = User::factory()->create(['last_login_at' => now()->subDays(120)]);
    AuditEvent::factory()->for($user)->signIn($userAgent)->daysAgo(120)->create();

    $this->withHeader('User-Agent', $userAgent)
        ->post(route('login.store'), ['email' => $user->email, 'password' => UserFactory::PASSWORD]);

    Notification::assertSentToTimes($user, NewDeviceSignIn::class, 1);
});

it('checks a password even for an unknown email, so both take as long', function () {
    $hasher = Mockery::mock(app('hash'));
    $hasher->shouldReceive('check')->once()->passthru();
    Hash::swap($hasher);

    $this->post(route('login.store'), ['email' => 'nobody@crms.test', 'password' => 'Some-Password-123!'])
        ->assertSessionHasErrors('email');
});

it('keeps other sessions signed in when a sign-in upgrades the password hash', function () {
    $user = User::factory()->withTwoFactor()->create();
    $originalHash = $user->password;
    Hash::driver('bcrypt')->setRounds(config('hashing.bcrypt.rounds') + 1);

    $this->actingAs($user)->get(route('dashboard'))->assertOk();

    app(AuthenticateUser::class)(Request::create('/login', 'POST', [
        'email' => $user->email,
        'password' => UserFactory::PASSWORD,
    ]));

    // Each request loads the user afresh, with the new hash.
    $user = $user->fresh();
    expect($user->password)->not->toBe($originalHash);

    $this->actingAs($user)->get(route('dashboard'))->assertOk();
});
