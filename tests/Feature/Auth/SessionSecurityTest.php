<?php

use App\Models\AuditEvent;
use App\Models\User;
use App\Services\AccountLifecycle;
use Illuminate\Support\Facades\DB;

it('signs the user out after the idle timeout', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)->get(route('dashboard'))->assertOk();

    $this->travel(config('crms.auth.idle_timeout'))->minutes();

    $this->get(route('dashboard'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', 'You were signed out after a period of inactivity.');

    $this->assertGuest();
    expect(AuditEvent::where('event', 'auth.session.expired')->sole()->context)->toBe(['reason' => 'idle_timeout']);
});

it('keeps an active user signed in when requests arrive within the idle window', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)->get(route('dashboard'));

    foreach (range(1, 3) as $step) {
        $this->travel(config('crms.auth.idle_timeout') - 1)->minutes();
        $this->postJson(route('session.heartbeat'))->assertOk();
    }

    $this->get(route('dashboard'))->assertOk();
});

it('ends the session at the absolute lifetime even when the user is active', function () {
    config(['crms.auth.absolute_lifetime' => 30]);
    $user = User::factory()->withTwoFactor()->create();
    $this->actingAs($user)->get(route('dashboard'));

    $elapsed = 0;
    while ($elapsed < 30) {
        $this->travel(10)->minutes();
        $elapsed += 10;
        $response = $this->get(route('dashboard'));
    }

    $response->assertRedirect(route('login'))
        ->assertSessionHas('status', 'Your session reached its maximum length. Please sign in again.');
});

it('returns 401 from the heartbeat once the session has expired', function () {
    $user = User::factory()->withTwoFactor()->create();
    $this->actingAs($user)->get(route('dashboard'));

    $this->travel(config('crms.auth.idle_timeout') + 1)->minutes();

    $this->postJson(route('session.heartbeat'))->assertUnauthorized();
});

it('ends a suspended user’s existing session on their next request', function () {
    $user = User::factory()->withTwoFactor()->create();
    $this->actingAs($user)->get(route('dashboard'))->assertOk();

    app(AccountLifecycle::class)->suspend($user, 'Left the service');

    $this->get(route('dashboard'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', 'Your session has ended because your account is no longer active.');

    $this->assertGuest();
    expect(AuditEvent::where('event', 'auth.session.terminated')->where('user_id', $user->id)->exists())->toBeTrue();
});

it('deletes a suspended user’s stored sessions immediately', function () {
    config(['session.driver' => 'database']);
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)->get(route('dashboard'));
    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(1);

    app(AccountLifecycle::class)->suspend($user, 'Left the service');

    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0);
});
