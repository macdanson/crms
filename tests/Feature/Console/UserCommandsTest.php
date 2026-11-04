<?php

use App\Enums\AccountStatus;
use App\Models\AuditEvent;
use App\Models\User;
use App\Notifications\AccountInvitation;
use App\Services\AccountLifecycle;
use App\Services\SessionPolicy;
use Illuminate\Support\Facades\Notification;

it('invites a user from the command line', function () {
    Notification::fake();

    $this->artisan('user:invite', ['--by' => 'admin@crms.test',
        'email' => 'Jane.Akello@crms.test',
        '--name' => 'Jane Akello',
        '--staff-number' => 'TEST-12345',
        '--job-title' => 'Crime Analyst',
    ])->assertSuccessful();

    $user = User::where('email', 'jane.akello@crms.test')->sole();
    expect($user->status)->toBe(AccountStatus::Pending)
        ->and($user->staff_number)->toBe('TEST-12345');

    Notification::assertSentTo($user, AccountInvitation::class);
});

it('refuses to invite with an invalid email address', function () {
    $this->artisan('user:invite', ['--by' => 'admin@crms.test', 'email' => 'not-an-email', '--name' => 'Jane Akello'])
        ->assertFailed();

    expect(User::count())->toBe(0);
});

it('suspends a user with a reason', function () {
    $user = User::factory()->create();

    $this->artisan('user:suspend', ['--by' => 'admin@crms.test', 'email' => $user->email, '--reason' => 'Transferred out'])
        ->assertSuccessful();

    expect($user->fresh()->status)->toBe(AccountStatus::Suspended)
        ->and($user->fresh()->suspension_reason)->toBe('Transferred out')
        ->and(AuditEvent::where('event', 'account.suspended')->sole()->context['reason'])->toBe('Transferred out');
});

it('reactivates a suspended user but not a pending one', function () {
    $suspended = User::factory()->suspended()->create();
    $pending = User::factory()->pending()->create();

    $this->artisan('user:reactivate', ['--by' => 'admin@crms.test', 'email' => $suspended->email])->assertSuccessful();
    $this->artisan('user:reactivate', ['--by' => 'admin@crms.test', 'email' => $pending->email])->assertFailed();

    expect($suspended->fresh()->status)->toBe(AccountStatus::Active)
        ->and($pending->fresh()->status)->toBe(AccountStatus::Pending);
});

it('resets a user’s authenticator', function () {
    $user = User::factory()->withTwoFactor()->create(['remember_token' => 'old-remember-token']);

    $this->artisan('user:reset-mfa', ['--by' => 'admin@crms.test', 'email' => $user->email])
        ->expectsConfirmation("Reset the authenticator for {$user->email}? Their sessions will be signed out.", 'yes')
        ->assertSuccessful();

    expect($user->fresh()->hasEnabledTwoFactorAuthentication())->toBeFalse()
        ->and($user->fresh()->remember_token)->not->toBe('old-remember-token');
});

it('reports an unknown user email', function () {
    $this->artisan('user:suspend', ['--by' => 'admin@crms.test', 'email' => 'nobody@crms.test', '--reason' => 'x'])->assertFailed();
});

it('records an authenticator reset once, not as MFA being switched off', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->artisan('user:reset-mfa', ['--by' => 'admin@crms.test', 'email' => $user->email])
        ->expectsConfirmation("Reset the authenticator for {$user->email}? Their sessions will be signed out.", 'yes')
        ->assertSuccessful();

    expect(AuditEvent::where('event', 'mfa.reset')->count())->toBe(1)
        ->and(AuditEvent::where('event', 'mfa.disabled')->exists())->toBeFalse();
});

it('returns a suspended invitation to pending instead of activating it', function () {
    Notification::fake();
    $user = app(AccountLifecycle::class)->invite(['name' => 'New User', 'email' => 'new.user@crms.test']);

    $this->artisan('user:suspend', ['--by' => 'admin@crms.test', 'email' => $user->email, '--reason' => 'Invited by mistake'])->assertSuccessful();
    $this->artisan('user:reactivate', ['--by' => 'admin@crms.test', 'email' => $user->email])->assertSuccessful();

    expect($user->fresh()->status)->toBe(AccountStatus::Pending);
});

it('finds the account whatever spacing or case the email is typed with', function () {
    $user = User::factory()->create(['email' => 'officer@crms.test']);

    $this->artisan('user:suspend', ['--by' => 'admin@crms.test', 'email' => '  Officer@CRMS.test ', '--reason' => 'Investigation'])->assertSuccessful();

    expect($user->fresh()->status)->toBe(AccountStatus::Suspended);
});

it('keeps the authenticator if its other sessions cannot be ended', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->mock(SessionPolicy::class)
        ->shouldReceive('endOtherSessions')
        ->andThrow(new RuntimeException('Session store unavailable'));

    expect(fn () => app(AccountLifecycle::class)->resetTwoFactor($user))->toThrow(RuntimeException::class);

    expect($user->fresh()->hasEnabledTwoFactorAuthentication())->toBeTrue();
});

it('records who ran a command, and no made-up address or browser', function () {
    $user = User::factory()->create();
    $admin = User::factory()->create();

    $this->artisan('user:suspend', ['email' => $user->email, '--reason' => 'Transferred out', '--by' => $admin->email])
        ->assertSuccessful();

    $event = AuditEvent::where('event', 'account.suspended')->sole();

    expect($event->context)->toMatchArray(['operator' => $admin->email, 'actor_id' => $admin->id])
        ->and($event->ip_address)->toBeNull()
        ->and($event->user_agent)->toBeNull();
});

it('refuses to run a command without knowing who is running it', function () {
    $user = User::factory()->create();

    $this->artisan('user:suspend', ['email' => $user->email, '--reason' => 'Transferred out'])
        ->expectsQuestion('Your name or email address, for the audit log', '')
        ->assertFailed();

    expect($user->fresh()->status)->toBe(AccountStatus::Active);
});

it('refuses a suspension reason too long to store, and leaves the account active', function () {
    $user = User::factory()->create();

    $this->artisan('user:suspend', ['email' => $user->email, '--reason' => str_repeat('x', 256), '--by' => 'admin@crms.test'])
        ->assertFailed();

    expect($user->fresh()->status)->toBe(AccountStatus::Active);
});

it('returns a suspended account to the status it had', function () {
    $inUse = User::factory()->create(['email_verified_at' => null]);
    $pending = User::factory()->pending()->create();
    $accounts = app(AccountLifecycle::class);

    $accounts->suspend($inUse, 'Investigation');
    $accounts->suspend($pending, 'Investigation');

    expect($accounts->reactivate($inUse->fresh()))->toBe(AccountStatus::Active)
        ->and($accounts->reactivate($pending->fresh()))->toBe(AccountStatus::Pending);
});

it('keeps a deactivated account deactivated when lifting a suspension on it', function () {
    $user = User::factory()->create(['status' => AccountStatus::Deactivated]);
    $accounts = app(AccountLifecycle::class);

    $accounts->suspend($user, 'Investigation');

    $this->artisan('user:reactivate', ['email' => $user->email, '--by' => 'admin@crms.test'])
        ->expectsOutputToContain('stays deactivated')
        ->assertSuccessful();

    expect($user->fresh()->status)->toBe(AccountStatus::Deactivated);

    // Asked for again, with nothing suspended, it restores the account.
    $this->artisan('user:reactivate', ['email' => $user->email, '--by' => 'admin@crms.test'])
        ->expectsOutputToContain('is active again')
        ->assertSuccessful();

    expect($user->fresh()->status)->toBe(AccountStatus::Active);
});
