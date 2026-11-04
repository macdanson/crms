<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    config(['app.debug' => false]);
});

it('renders the branded error page for an unknown address', function () {
    $this->get('/does-not-exist')
        ->assertNotFound()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Error')
            ->where('status', 404)
            ->where('productName', 'CRMS'));
});

it('returns JSON errors to JSON clients instead of the error page', function () {
    $this->getJson('/does-not-exist')
        ->assertNotFound()
        ->assertHeader('Content-Type', 'application/json')
        ->assertJsonStructure(['message']);
});

it('keeps the rate limit headers on the error page', function () {
    Route::get('/limited', fn () => 'ok')->middleware('throttle:1,1');

    $this->get('/limited')->assertOk();

    $this->get('/limited')
        ->assertTooManyRequests()
        ->assertHeader('Retry-After')
        ->assertHeader('X-RateLimit-Limit');
});

it('does not send an Inertia visit to a limited page round in a circle', function () {
    Route::middleware('web')->get('/limited', fn () => 'ok')->middleware('throttle:1,1');
    $version = app(HandleInertiaRequests::class)->version(request());
    $headers = ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) $version, 'Referer' => url('/limited')];

    $this->get('/limited', $headers);

    $this->get('/limited', $headers)->assertTooManyRequests();
});

it('falls back to the framework error page when the error page cannot be built', function () {
    Exceptions::fake();
    Inertia::setRootView('a-view-that-does-not-exist');

    $this->get('/does-not-exist')->assertNotFound();
});

it('records a request refused for lack of permission', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->getJson(route('two-factor.secret-key'))
        ->assertForbidden();

    $event = AuditEvent::where('event', 'auth.access.denied')->sole();

    expect($event->user_id)->toBe($user->id)
        ->and($event->context)->toMatchArray(['method' => 'GET', 'route' => 'two-factor.secret-key']);
});
