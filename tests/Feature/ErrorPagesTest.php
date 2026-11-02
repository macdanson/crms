<?php

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
