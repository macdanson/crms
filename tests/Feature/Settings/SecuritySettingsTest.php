<?php

use App\Models\AuditEvent;
use App\Models\User;
use App\Services\BrowserSessions;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia;

/**
 * Inserts a stored session for the user as if signed in on another device.
 */
function storeOtherSession(User $user, string $id = 'other-device-session-id'): string
{
    DB::table('sessions')->insert([
        'id' => $id,
        'user_id' => $user->id,
        'ip_address' => '10.20.30.40',
        'user_agent' => 'Mozilla/5.0 (Linux; Android 14) Mobile Safari/537.36',
        'payload' => base64_encode(serialize([])),
        'last_activity' => now()->getTimestamp(),
    ]);

    return $id;
}

it('asks for the password again before showing security settings', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)
        ->get(route('security.show'))
        ->assertRedirect(route('password.confirm'));
});

it('shows the user their account, authenticator and activity', function () {
    $user = User::factory()->withTwoFactor()->create();
    AuditEvent::create([
        'occurred_at' => now(),
        'user_id' => $user->id,
        'event' => 'auth.login',
        'outcome' => 'success',
        'user_agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/130.0',
    ]);

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.show'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('settings/Security')
            ->where('account.email', $user->email)
            ->where('twoFactor.enabled', true)
            ->where('twoFactor.recovery_codes_remaining', 8)
            ->where('activity.0.event', 'auth.login')
            ->where('activity.0.device', 'Chrome on Windows'));
});

it('never sends the authenticator secret or recovery codes in shared page data', function () {
    $user = User::factory()->withTwoFactor()->create();

    $response = $this->actingAs($user)->get(route('dashboard'));

    expect($response->inertiaProps('auth.user'))->not->toHaveKeys(['two_factor_secret', 'two_factor_recovery_codes', 'password'])
        ->and($response->getContent())->not->toContain($user->two_factor_secret);
});

it('changes the password, signs out other sessions and keeps this one', function () {
    config(['session.driver' => 'database']);
    $user = User::factory()->withTwoFactor()->create();
    storeOtherSession($user);

    $this->actingAs($user)
        ->put(route('user-password.update'), [
            'current_password' => UserFactory::PASSWORD,
            'password' => 'Changed-Password-2026!',
            'password_confirmation' => 'Changed-Password-2026!',
        ])
        ->assertSessionHasNoErrors();

    expect(Hash::check('Changed-Password-2026!', $user->fresh()->password))->toBeTrue()
        ->and(DB::table('sessions')->where('id', 'other-device-session-id')->exists())->toBeFalse()
        ->and(AuditEvent::where('event', 'auth.password.changed')->exists())->toBeTrue();

    $this->assertAuthenticatedAs($user);
});

it('rejects a password change with the wrong current password', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)
        ->put(route('user-password.update'), [
            'current_password' => 'Not-My-Password-1!',
            'password' => 'Changed-Password-2026!',
            'password_confirmation' => 'Changed-Password-2026!',
        ])
        ->assertSessionHasErrors(['current_password' => 'The provided password does not match your current password.']);
});

it('rejects reusing the current password', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)
        ->put(route('user-password.update'), [
            'current_password' => UserFactory::PASSWORD,
            'password' => UserFactory::PASSWORD,
            'password_confirmation' => UserFactory::PASSWORD,
        ])
        ->assertSessionHasErrors(['password' => 'Your new password must be different from your current password.']);
});

it('lists the user’s sessions without exposing raw session identifiers', function () {
    config(['session.driver' => 'database']);
    $user = User::factory()->withTwoFactor()->create();
    storeOtherSession($user);

    $response = $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.show'));

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->has('sessions', 1)
        ->where('sessions.0.platform', 'Android')
        ->where('sessions.0.is_current', false));

    expect($response->getContent())->not->toContain('other-device-session-id');
});

it('signs out a single other session', function () {
    config(['session.driver' => 'database']);
    $user = User::factory()->withTwoFactor()->create();
    $sessionId = storeOtherSession($user);

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->delete(route('security.sessions.destroy', app(BrowserSessions::class)->publicId($sessionId)))
        ->assertSessionHas('status', 'That session has been signed out.');

    expect(DB::table('sessions')->where('id', $sessionId)->exists())->toBeFalse();
});

it('cannot sign out another user’s session', function () {
    config(['session.driver' => 'database']);
    $user = User::factory()->withTwoFactor()->create();
    $otherUser = User::factory()->withTwoFactor()->create();
    $sessionId = storeOtherSession($otherUser);

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->delete(route('security.sessions.destroy', app(BrowserSessions::class)->publicId($sessionId)))
        ->assertSessionHas('error');

    expect(DB::table('sessions')->where('id', $sessionId)->exists())->toBeTrue();
});

it('signs out all other sessions after checking the password', function () {
    config(['session.driver' => 'database']);
    $user = User::factory()->withTwoFactor()->create();
    storeOtherSession($user);

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->delete(route('security.sessions.destroy-others'), ['password' => 'Wrong-Password-1!'])
        ->assertSessionHasErrors(['password' => 'The password is incorrect.']);

    expect(DB::table('sessions')->where('id', 'other-device-session-id')->exists())->toBeTrue();

    $this->delete(route('security.sessions.destroy-others'), ['password' => UserFactory::PASSWORD])
        ->assertSessionHas('status', 'All other sessions have been signed out.');

    expect(DB::table('sessions')->where('id', 'other-device-session-id')->exists())->toBeFalse();
});

it('replaces the authenticator and sends the user to re-enrol', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('security.two-factor.reset'))
        ->assertRedirect(route('two-factor.setup'));

    expect($user->fresh()->hasEnabledTwoFactorAuthentication())->toBeFalse()
        ->and(AuditEvent::where('event', 'mfa.reset')->where('user_id', $user->id)->exists())->toBeTrue();

    $this->get(route('dashboard'))->assertRedirect(route('two-factor.setup'));
});

it('keeps the user signed in on the next page after changing the password', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.show'))
        ->assertOk();

    $this->put(route('user-password.update'), [
        'current_password' => UserFactory::PASSWORD,
        'password' => 'Changed-Password-2026!',
        'password_confirmation' => 'Changed-Password-2026!',
    ])->assertSessionHasNoErrors();

    $this->get(route('security.show'))->assertOk();
    $this->assertAuthenticatedAs($user);
});

it('returns to the page an action came from after confirming the password', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)
        ->from(route('security.show'))
        ->delete(route('security.sessions.destroy-others'), ['password' => UserFactory::PASSWORD])
        ->assertRedirect(route('password.confirm'));

    $this->post(route('password.confirm.store'), ['password' => UserFactory::PASSWORD])
        ->assertRedirect(route('security.show'));
});

it('never returns to another site after confirming the password', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)
        ->withHeader('referer', 'https://attacker.example/page')
        ->delete(route('security.sessions.destroy-others'), ['password' => UserFactory::PASSWORD])
        ->assertRedirect(route('password.confirm'));

    $this->post(route('password.confirm.store'), ['password' => UserFactory::PASSWORD])
        ->assertRedirect(route('dashboard'));
});
