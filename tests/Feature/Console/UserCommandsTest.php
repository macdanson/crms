<?php

use App\Enums\AccountStatus;
use App\Models\AuditEvent;
use App\Models\User;
use App\Notifications\AccountInvitation;
use Illuminate\Support\Facades\Notification;

it('invites a user from the command line', function () {
    Notification::fake();

    $this->artisan('user:invite', [
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
    $this->artisan('user:invite', ['email' => 'not-an-email', '--name' => 'Jane Akello'])
        ->assertFailed();

    expect(User::count())->toBe(0);
});

it('suspends a user with a reason', function () {
    $user = User::factory()->create();

    $this->artisan('user:suspend', ['email' => $user->email, '--reason' => 'Transferred out'])
        ->assertSuccessful();

    expect($user->fresh()->status)->toBe(AccountStatus::Suspended)
        ->and($user->fresh()->suspension_reason)->toBe('Transferred out')
        ->and(AuditEvent::where('event', 'account.suspended')->sole()->context['reason'])->toBe('Transferred out');
});

it('reactivates a suspended user but not a pending one', function () {
    $suspended = User::factory()->suspended()->create();
    $pending = User::factory()->pending()->create();

    $this->artisan('user:reactivate', ['email' => $suspended->email])->assertSuccessful();
    $this->artisan('user:reactivate', ['email' => $pending->email])->assertFailed();

    expect($suspended->fresh()->status)->toBe(AccountStatus::Active)
        ->and($pending->fresh()->status)->toBe(AccountStatus::Pending);
});

it('resets a user’s authenticator', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->artisan('user:reset-mfa', ['email' => $user->email])
        ->expectsConfirmation("Reset the authenticator for {$user->email}? Their sessions will be signed out.", 'yes')
        ->assertSuccessful();

    expect($user->fresh()->hasEnabledTwoFactorAuthentication())->toBeFalse();
});

it('reports an unknown user email', function () {
    $this->artisan('user:suspend', ['email' => 'nobody@crms.test', '--reason' => 'x'])->assertFailed();
});
