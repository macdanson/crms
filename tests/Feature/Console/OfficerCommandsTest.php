<?php

use App\Enums\AccountStatus;
use App\Models\AuditEvent;
use App\Models\User;
use App\Notifications\AccountInvitation;
use Illuminate\Support\Facades\Notification;

it('invites an officer from the command line', function () {
    Notification::fake();

    $this->artisan('officer:invite', [
        'email' => 'Jane.Akello@crms.test',
        '--name' => 'Jane Akello',
        '--service-number' => 'TEST-12345',
        '--rank' => 'Sergeant',
    ])->assertSuccessful();

    $user = User::where('email', 'jane.akello@crms.test')->sole();
    expect($user->status)->toBe(AccountStatus::Pending)
        ->and($user->service_number)->toBe('TEST-12345');

    Notification::assertSentTo($user, AccountInvitation::class);
});

it('refuses to invite with an invalid email address', function () {
    $this->artisan('officer:invite', ['email' => 'not-an-email', '--name' => 'Jane Akello'])
        ->assertFailed();

    expect(User::count())->toBe(0);
});

it('suspends an officer with a reason', function () {
    $user = User::factory()->create();

    $this->artisan('officer:suspend', ['email' => $user->email, '--reason' => 'Transferred out'])
        ->assertSuccessful();

    expect($user->fresh()->status)->toBe(AccountStatus::Suspended)
        ->and($user->fresh()->suspension_reason)->toBe('Transferred out')
        ->and(AuditEvent::where('event', 'account.suspended')->sole()->context['reason'])->toBe('Transferred out');
});

it('reactivates a suspended officer but not a pending one', function () {
    $suspended = User::factory()->suspended()->create();
    $pending = User::factory()->pending()->create();

    $this->artisan('officer:reactivate', ['email' => $suspended->email])->assertSuccessful();
    $this->artisan('officer:reactivate', ['email' => $pending->email])->assertFailed();

    expect($suspended->fresh()->status)->toBe(AccountStatus::Active)
        ->and($pending->fresh()->status)->toBe(AccountStatus::Pending);
});

it('resets an officer’s authenticator', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->artisan('officer:reset-mfa', ['email' => $user->email])
        ->expectsConfirmation("Reset the authenticator for {$user->email}? Their sessions will be signed out.", 'yes')
        ->assertSuccessful();

    expect($user->fresh()->hasEnabledTwoFactorAuthentication())->toBeFalse();
});

it('reports an unknown officer email', function () {
    $this->artisan('officer:suspend', ['email' => 'nobody@crms.test', '--reason' => 'x'])->assertFailed();
});
