<?php

use App\Models\AuditEvent;
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

it('takes the client address and HTTPS from a trusted proxy', function () {
    config(['trustedproxy.proxies' => '10.0.0.1']);

    $response = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
        ->withHeaders(['X-Forwarded-For' => '203.0.113.7', 'X-Forwarded-Proto' => 'https'])
        ->post(route('login.store'), ['email' => 'nobody@crms.test', 'password' => 'wrong-password']);

    $response->assertHeader('Strict-Transport-Security');

    expect(AuditEvent::where('event', 'auth.login.failed')->sole()->ip_address)->toBe('203.0.113.7');
});

it('ignores forwarded addresses from anyone else', function () {
    config(['trustedproxy.proxies' => '10.0.0.1']);

    $response = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])
        ->withHeaders(['X-Forwarded-For' => '203.0.113.7', 'X-Forwarded-Proto' => 'https'])
        ->post(route('login.store'), ['email' => 'nobody@crms.test', 'password' => 'wrong-password']);

    $response->assertHeaderMissing('Strict-Transport-Security');

    expect(AuditEvent::where('event', 'auth.login.failed')->sole()->ip_address)->toBe('198.51.100.9');
});

it('reads the trusted proxies from the environment, and trusts none by default', function () {
    expect(config('trustedproxy.proxies'))->toBeNull();

    $_ENV['TRUSTED_PROXIES'] = $_SERVER['TRUSTED_PROXIES'] = '10.0.0.1,10.0.0.2';

    $configured = require config_path('trustedproxy.php');

    $_ENV['TRUSTED_PROXIES'] = $_SERVER['TRUSTED_PROXIES'] = '';

    $blank = require config_path('trustedproxy.php');

    unset($_ENV['TRUSTED_PROXIES'], $_SERVER['TRUSTED_PROXIES']);

    expect($configured['proxies'])->toBe('10.0.0.1,10.0.0.2')
        ->and($blank['proxies'])->toBeNull();
});
