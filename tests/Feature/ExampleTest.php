<?php

use App\Models\User;

test('the root url sends guests to the login form', function () {
    $this->get(route('home'))->assertRedirect(route('login'));
});

test('the root url sends signed-in users to their portal', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('home'))->assertRedirect(route('dashboard'));
});
