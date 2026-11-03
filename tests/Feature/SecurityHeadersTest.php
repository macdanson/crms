<?php

use App\Models\User;
use Illuminate\Http\Middleware\TrustProxies;

afterEach(function () {
    TrustProxies::flushState();
});

it('sends browser hardening headers on every page', function () {
    $this->get(route('login'))
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('X-Request-Id');
});

it('sends a content security policy with a per-request script nonce', function () {
    config(['crms.security.csp' => true]);

    $policy = $this->get(route('login'))->headers->get('Content-Security-Policy');

    expect($policy)->toContain("frame-ancestors 'none'")
        ->toContain("object-src 'none'")
        ->toMatch("/script-src 'self' 'nonce-[A-Za-z0-9]+'/");
});

it('stops browsers caching authenticated pages', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertHeader('Cache-Control', 'must-revalidate, no-cache, no-store, private');
});

it('reuses a well-formed request id sent by a trusted proxy', function () {
    TrustProxies::at('127.0.0.1');

    $this->withHeader('X-Request-Id', 'edge-proxy-1234abcd')
        ->get(route('login'))
        ->assertHeader('X-Request-Id', 'edge-proxy-1234abcd');
});

it('ignores a request id sent by anyone other than a trusted proxy', function () {
    $response = $this->withHeader('X-Request-Id', 'edge-proxy-1234abcd')->get(route('login'));

    expect($response->headers->get('X-Request-Id'))->not->toBe('edge-proxy-1234abcd')
        ->toMatch('/^[0-9a-f-]{36}$/');
});

it('keeps the session cookie on links from other sites', function () {
    $response = $this->get(route('login'));

    $sessionCookie = collect($response->headers->getCookies())
        ->first(fn ($cookie) => $cookie->getName() === config('session.cookie'));

    expect($sessionCookie?->getSameSite())->toBe('lax');
});

it('sends hardening headers on the response that ends a session', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)->get(route('dashboard'))->assertOk();
    $this->travel(config('crms.auth.idle_timeout'))->minutes();

    $this->get(route('dashboard'))
        ->assertRedirect(route('login'))
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Cache-Control', 'must-revalidate, no-cache, no-store, private');
});

it('sends hardening headers on pages that do not exist', function () {
    $this->get('/no-such-page')
        ->assertNotFound()
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});
