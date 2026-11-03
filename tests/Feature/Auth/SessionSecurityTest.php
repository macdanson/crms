<?php

use App\Models\AuditEvent;
use App\Models\User;
use App\Services\AccountLifecycle;
use App\Services\SessionPolicy;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;

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
        $this->postJson(route('session.heartbeat'))->assertNoContent();
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

it('returns 401 JSON to the heartbeat of a suspended account', function () {
    $user = User::factory()->withTwoFactor()->create();
    $this->actingAs($user)->get(route('dashboard'))->assertOk();

    $user->forceFill(['status' => 'suspended'])->save();

    $this->postJson(route('session.heartbeat'))->assertUnauthorized();
});

it('does not record a timed-out session as a sign-out by the user', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)->get(route('dashboard'))->assertOk();
    $this->travel(config('crms.auth.idle_timeout'))->minutes();
    $this->get(route('dashboard'));

    expect(AuditEvent::where('event', 'auth.session.expired')->exists())->toBeTrue()
        ->and(AuditEvent::where('event', 'auth.logout')->exists())->toBeFalse();
});

it('does not record the end of a suspended user’s session as a sign-out by the user', function () {
    $user = User::factory()->withTwoFactor()->create();
    $this->actingAs($user)->get(route('dashboard'))->assertOk();

    $user->forceFill(['status' => 'suspended'])->save();
    $this->get(route('dashboard'));

    expect(AuditEvent::where('event', 'auth.session.terminated')->exists())->toBeTrue()
        ->and(AuditEvent::where('event', 'auth.logout')->exists())->toBeFalse();
});

it('sends an Inertia action made after the idle timeout to the sign-in page with GET', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)->get(route('dashboard'))->assertOk();
    $this->travel(config('crms.auth.idle_timeout'))->minutes();

    $this->withHeader('X-Inertia', 'true')
        ->delete(route('security.sessions.destroy-others'))
        ->assertStatus(303)
        ->assertRedirect(route('login'));
});

it('ends other sessions after an authenticator reset with any session driver', function () {
    expect(config('session.driver'))->not->toBe('database');
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)->get(route('dashboard'))->assertOk();

    app(AccountLifecycle::class)->resetTwoFactor($user);

    $this->get(route('dashboard'))->assertRedirect(route('login'));
    $this->assertGuest();
    expect(AuditEvent::where('event', 'auth.session.invalidated')->where('user_id', $user->id)->exists())->toBeTrue();
});

it('keeps the session of a user who replaces their own authenticator', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('security.two-factor.reset'), ['code' => currentAuthenticatorCode($user)])
        ->assertRedirect(route('two-factor.setup'));

    $this->get(route('two-factor.setup'))->assertOk();
    $this->assertAuthenticatedAs($user);
});

it('tells the page how long the session has before its maximum length', function () {
    config(['crms.auth.absolute_lifetime' => 30]);
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)->get(route('dashboard'))->assertOk();
    $this->travel(10)->minutes();

    $this->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('session.absolute_remaining', 20 * 60)
            ->where('session.absolute_warning', config('crms.auth.absolute_warning')));
});

it('tells a user whose heartbeat finds the session ended why they were signed out', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)->get(route('dashboard'))->assertOk();
    $this->travel(config('crms.auth.idle_timeout'))->minutes();

    $this->postJson(route('session.heartbeat'))->assertUnauthorized();

    $this->get(route('login'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('flash.status', 'You were signed out after a period of inactivity.'));
});

it('signs out a session that was never stamped at sign-in', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)->flushSession();

    $this->get(route('dashboard'))->assertRedirect(route('login'));
    $this->assertGuest();
});

it('signs out a suspended user even if the account is reactivated first', function () {
    $user = User::factory()->withTwoFactor()->create();
    $lifecycle = app(AccountLifecycle::class);

    $this->actingAs($user)->get(route('dashboard'))->assertOk();

    $lifecycle->suspend($user, 'Investigation');
    $lifecycle->reactivate($user);

    $this->get(route('dashboard'))->assertRedirect(route('login'));
    $this->assertGuest();
});

it('shows the sign-out reason after the browser reloads a protected page', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)->get(route('dashboard'))->assertOk();
    $this->travel(config('crms.auth.idle_timeout'))->minutes();

    $this->postJson(route('session.heartbeat'))->assertUnauthorized();
    $this->get(route('dashboard'))->assertRedirect(route('login'));

    $this->get(route('login'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('flash.status', 'You were signed out after a period of inactivity.'));
});

it('keeps the session that ended the others even if an earlier request saves the old stamp back', function () {
    $user = User::factory()->create();
    $policy = app(SessionPolicy::class);
    $newSession = function (): Store {
        $session = new Store('crms_test', new ArraySessionHandler(120));
        $session->start();

        return $session;
    };

    $keptSession = $newSession();
    $otherSession = $newSession();
    $policy->start($keptSession, $user);
    $policy->start($otherSession, $user);
    $stampBefore = $keptSession->get(SessionPolicy::STAMP);

    $policy->endOtherSessions($user, $keptSession);

    // A request the kept session had in flight saves the session it read
    // before the change, with the old stamp.
    $keptSession->put(SessionPolicy::STAMP, $stampBefore);

    expect($policy->isCurrent($keptSession, $user->fresh()))->toBeTrue()
        ->and($policy->isCurrent($otherSession, $user->fresh()))->toBeFalse();
});

it('sends a signed-in user back to try again after an expired form token', function () {
    Route::post('_test/expired-token', fn () => throw new TokenMismatchException)->middleware('web');
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)
        ->from(route('dashboard'))
        ->post('_test/expired-token')
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('error', 'This page had expired, so your last action was not saved. Please try again.');

    $this->assertAuthenticatedAs($user);
});

it('sends a guest to sign in after an expired form token', function () {
    Route::post('_test/expired-token', fn () => throw new TokenMismatchException)->middleware('web');

    $this->post('_test/expired-token')
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', 'Your session expired. Please sign in again.');
});
