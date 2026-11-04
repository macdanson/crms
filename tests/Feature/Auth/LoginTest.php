<?php

use App\Actions\Fortify\AuthenticateUser;
use App\Enums\AuditOutcome;
use App\Models\AuditEvent;
use App\Models\User;
use App\Notifications\NewDeviceSignIn;
use App\Notifications\SignInPasswordsLocked;
use App\Services\AccountAttemptLimiter;
use App\Services\AuditLogger;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Notifications\Dispatcher as NotificationDispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
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

    // A real sign-in: the session holds the stamp it was given, which
    // actingAs() would give it again on every call.
    $this->post(route('login.store'), ['email' => $user->email, 'password' => UserFactory::PASSWORD]);
    $this->post(route('two-factor.login.store'), ['code' => currentAuthenticatorCode($user)]);
    $this->get(route('dashboard'))->assertOk();

    Hash::driver('bcrypt')->setRounds(config('hashing.bcrypt.rounds') + 1);

    app(AuthenticateUser::class)(Request::create('/login', 'POST', [
        'email' => $user->email,
        'password' => UserFactory::PASSWORD,
    ]));

    expect($user->fresh()->password)->not->toBe($originalHash);

    // Each request loads the user afresh, with the new hash.
    Auth::forgetGuards();

    $this->get(route('dashboard'))->assertOk();
});

it('gives every new account a session stamp, so signing in never creates one', function () {
    $user = User::factory()->create();

    expect($user->session_stamp)->toBeString()->toHaveLength(64);

    $this->post(route('login.store'), ['email' => $user->email, 'password' => UserFactory::PASSWORD]);

    expect($user->fresh()->session_stamp)->toBe($user->session_stamp);
});

it('does not keep a password typed into the email field of a failed sign-in', function () {
    $this->post(route('login.store'), ['email' => 'Secret-Pass-123!', 'password' => 'anything'])
        ->assertSessionHasErrors('email');

    $context = AuditEvent::where('event', 'auth.login.failed')->sole()->context;

    expect($context)->not->toHaveKey('email')
        ->and(json_encode($context))->not->toContain('Secret-Pass-123!');
});

it('keeps the email address of a failed sign-in for an unknown account', function () {
    $this->post(route('login.store'), ['email' => ' Nobody@CRMS.test ', 'password' => 'anything']);

    expect(AuditEvent::where('event', 'auth.login.failed')->sole()->context)->toBe(['email' => 'nobody@crms.test']);
});

it('stores a user agent that is not valid text as valid text', function () {
    $this->withHeader('User-Agent', "Mozilla\xFF\xFE/5.0")
        ->post(route('login.store'), ['email' => 'nobody@crms.test', 'password' => 'wrong-password'])
        ->assertSessionHasErrors('email');

    $userAgent = AuditEvent::where('event', 'auth.login.failed')->sole()->user_agent;

    expect(mb_check_encoding($userAgent, 'UTF-8'))->toBeTrue()
        ->and($userAgent)->toStartWith('Mozilla');
});

it('cuts a long user agent on a character boundary, as sessions store at most 500 bytes', function () {
    $this->withHeader('User-Agent', str_repeat('é', 400))
        ->post(route('login.store'), ['email' => 'nobody@crms.test', 'password' => 'wrong-password']);

    $userAgent = request()->userAgent();

    expect(strlen($userAgent))->toBeLessThanOrEqual(500)
        ->and(mb_check_encoding($userAgent, 'UTF-8'))->toBeTrue();
});

it('still counts a failed sign-in when it cannot be recorded', function () {
    Exceptions::fake();
    $this->mock(AuditLogger::class)->shouldReceive('record')->andThrow(new RuntimeException('Database refused the record'));

    $this->post(route('login.store'), ['email' => 'nobody@crms.test', 'password' => 'wrong-password'])
        ->assertSessionHasErrors('email');

    expect(RateLimiter::attempts('nobody@crms.test|127.0.0.1'))->toBe(1);
});

it('treats an email address that is not valid text as unknown', function () {
    expect(User::findByEmail("\xFF@crms.test"))->toBeNull();

    $this->post(route('login.store'), ['email' => "\xFF@crms.test", 'password' => 'wrong-password'])
        ->assertSessionHasErrors('email');
});

it('finds an account by its email address whatever its case or spacing', function () {
    $user = User::factory()->create(['email' => 'user@crms.test']);

    expect(User::findByEmail(' USER@crms.test ')?->id)->toBe($user->id);
});

it('does not reach an account through text the database only treats as equal', function (string $variant) {
    $user = User::factory()->create(['email' => 'alice@crms.test']);

    expect(User::findByEmail($variant))->toBeNull();

    $this->post(route('login.store'), ['email' => $variant, 'password' => UserFactory::PASSWORD])
        ->assertSessionHasErrors('email');

    $this->assertGuest();

    expect(AuditEvent::where('event', 'auth.login.failed')->where('user_id', $user->id)->exists())->toBeFalse();
})->with([
    'zero-width space' => "al\u{200B}ice@crms.test",
    'combining joiner' => "al\u{034F}ice@crms.test",
    'variation selector' => "al\u{FE00}ice@crms.test",
    'accent' => 'álice@crms.test',
]);

it('pauses sign-in for an account after too many wrong passwords from any address', function () {
    Notification::fake();
    $user = User::factory()->withTwoFactor()->create();
    $limiter = app(AccountAttemptLimiter::class);

    startAttemptWindow();

    foreach (range(1, 20) as $attempt) {
        $limiter->attempt(AccountAttemptLimiter::SIGN_IN_PASSWORD, $user, fn (): bool => false);
    }

    // The right password, from a new address, gets the same answer as a wrong one.
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
        ->post(route('login.store'), ['email' => $user->email, 'password' => UserFactory::PASSWORD])
        ->assertSessionHasErrors(['email' => __('auth.failed')]);

    $this->assertGuest();

    expect(session()->has('login.id'))->toBeFalse()
        ->and(AuditEvent::where('event', 'auth.lockout')->where('user_id', $user->id)->exists())->toBeTrue();

    Notification::assertSentToTimes($user, SignInPasswordsLocked::class, 1);
});

it('does not pause sign-in for a few wrong passwords', function () {
    $user = User::factory()->withTwoFactor()->create();
    $limiter = app(AccountAttemptLimiter::class);

    startAttemptWindow();

    foreach (range(1, 19) as $attempt) {
        $limiter->attempt(AccountAttemptLimiter::SIGN_IN_PASSWORD, $user, fn (): bool => false);
    }

    $this->post(route('login.store'), ['email' => $user->email, 'password' => UserFactory::PASSWORD])
        ->assertRedirect(route('two-factor.login'));
});

it('still signs in when the new-device alert cannot be sent, and records it', function () {
    Exceptions::fake();
    $this->mock(NotificationDispatcher::class)
        ->shouldReceive('send')
        ->andThrow(new RuntimeException('Mail server unavailable'));
    $user = User::factory()->create(['last_login_at' => now()->subDay()]);

    $this->withHeader('User-Agent', 'Mozilla/5.0 (Linux; Android 14) Mobile Safari/537.36')
        ->post(route('login.store'), ['email' => $user->email, 'password' => UserFactory::PASSWORD])
        ->assertRedirect();

    $this->assertAuthenticatedAs($user);

    expect(AuditEvent::where('event', 'auth.login')->where('user_id', $user->id)->exists())->toBeTrue()
        ->and($user->fresh()->last_login_at->isToday())->toBeTrue();
});

it('takes the same minimum time for a wrong password as for an unknown email address', function () {
    $user = User::factory()->withTwoFactor()->create();

    foreach ([$user->email, 'nobody@crms.test'] as $email) {
        $startedAt = microtime(true);

        $this->post(route('login.store'), ['email' => $email, 'password' => 'wrong-password'])
            ->assertSessionHasErrors('email');

        expect(microtime(true) - $startedAt)->toBeGreaterThanOrEqual(0.5);
    }
});

it('does not send an email address holding a NUL byte to the database', function () {
    DB::enableQueryLog();

    expect(User::findByEmail("a\0b@crms.test"))->toBeNull()
        ->and(DB::getQueryLog())->toBeEmpty();

    $this->post(route('login.store'), ['email' => "a\0b@crms.test", 'password' => 'wrong-password'])
        ->assertSessionHasErrors('email');
});

it('answers a NUL byte in a password like any wrong password', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => "wrong\0password"])
        ->assertSessionHasErrors('email');
});
