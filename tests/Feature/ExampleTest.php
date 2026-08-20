<?php

use Inertia\Testing\AssertableInertia;

test('returns a successful response', function () {
    $response = $this->get(route('home'));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->component('Welcome'));
});
