<?php

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    RateLimiter::clear('password-reset');
});

it('throttles repeated password reset requests for one address', function () {
    $user = User::factory()->create(['email' => 'grace@example.test']);

    for ($attempt = 0; $attempt < 3; $attempt++) {
        $this->post('/forgot-password', ['email' => $user->email])->assertRedirect();
    }

    $this->post('/forgot-password', ['email' => $user->email])->assertStatus(429);
});

it('throttles the reset form itself, not only the request for a link', function () {
    for ($attempt = 0; $attempt < 3; $attempt++) {
        $this->post('/reset-password', [
            'token' => 'nonsense',
            'email' => 'grace@example.test',
            'password' => 'password-attempt',
            'password_confirmation' => 'password-attempt',
        ]);
    }

    $this->post('/reset-password', [
        'token' => 'nonsense',
        'email' => 'grace@example.test',
        'password' => 'password-attempt',
        'password_confirmation' => 'password-attempt',
    ])->assertStatus(429);
});

it('locks a single address out of login after five attempts a minute', function () {
    $user = User::factory()->create(['email' => 'chola@example.test']);

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])
            ->assertSessionHasErrors('email');
    }

    $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])->assertStatus(429);
});

it('locks an address out of login attempts across the whole register', function () {
    /*
     * The group's emails are predictable from their names, so somebody working down
     * the register would never trip the per-account limit. The hourly limit is keyed
     * on the caller instead, which is what stops that.
     */
    for ($attempt = 0; $attempt < 20; $attempt++) {
        $this->post('/login', ['email' => "member{$attempt}@example.test", 'password' => 'wrong']);
    }

    $this->post('/login', ['email' => 'member99@example.test', 'password' => 'wrong'])
        ->assertStatus(429);
});
