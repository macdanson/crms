<?php

use App\Models\User;

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

it('reuses a well-formed incoming request id for tracing', function () {
    $this->withHeader('X-Request-Id', 'station-proxy-1234abcd')
        ->get(route('login'))
        ->assertHeader('X-Request-Id', 'station-proxy-1234abcd');
});
