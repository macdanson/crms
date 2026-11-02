<?php

test('the home page sends visitors into the application', function () {
    $response = $this->get(route('home'));

    $response->assertRedirect(route('dashboard'));
});
