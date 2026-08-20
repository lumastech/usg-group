<?php

use App\Enums\MemberRole;
use App\Models\Cycle;
use App\Models\Member;
use App\Models\User;
use Database\Seeders\RoleSeeder;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

it('sends guests to the login page', function () {
    $this->get(route('app.dashboard'))->assertRedirect(route('login'));
    $this->get(route('my.dashboard'))->assertRedirect(route('login'));
});

it('renders the committee dashboard', function () {
    Cycle::factory()->create();

    $user = User::factory()->create();
    $user->assignRole(MemberRole::Treasurer->value);

    $this->actingAs($user)
        ->get(route('app.dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('app/Dashboard'));
});

it('renders the committee dashboard without a cycle rather than failing', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('app.dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('app/Dashboard')->where('overview', null));
});

it('renders the member dashboard with the signed-in member', function () {
    $cycle = Cycle::factory()->create();
    $user = User::factory()->create();
    $member = Member::factory()->for($cycle)->create(['user_id' => $user->id, 'full_name' => 'Bertha Chileshe']);

    $this->actingAs($user)
        ->get(route('my.dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('my/Dashboard')
            ->where('member.id', $member->id)
            ->where('member.full_name', 'Bertha Chileshe')
        );
});

it('renders the member dashboard for a user with no member record', function () {
    Cycle::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('my.dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('member', null));
});

it('renders the styleguide with the role and permission matrix', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('app.styleguide'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('app/Styleguide')
            ->has('roles', count(MemberRole::cases()))
            ->has('permissions')
            ->where('roles.0.value', MemberRole::Member->value)
        );
});
