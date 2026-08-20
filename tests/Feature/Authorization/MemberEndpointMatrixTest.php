<?php

use App\Enums\MemberRole;
use App\Enums\MemberStatus;
use App\Models\Cycle;
use App\Models\Member;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;

/**
 * Every role against every members endpoint.
 *
 * The dataset is the authorisation matrix in one place: if an office is re-scoped
 * in MemberRole::permissions(), exactly the rows that should change here fail.
 * Reading the register follows `members.view`, `members.manage` or `reports.view`;
 * writing to it follows `members.manage` alone.
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->travelTo(Carbon::parse('2026-01-10'));

    $this->cycle = Cycle::factory()->create();
    $this->member = Member::factory()->for($this->cycle)->create(['user_id' => null]);
});

/** Sign in as a user holding one role, with no member record of their own. */
function actingAsRole(MemberRole $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role->value);

    test()->actingAs($user);

    return $user;
}

/** The endpoints, as [method, route name] against the seeded member. */
dataset('member endpoints', [
    'index' => ['get', 'app.members.index'],
    'create' => ['get', 'app.members.create'],
    'show' => ['get', 'app.members.show'],
    'store' => ['post', 'app.members.store'],
    'edit' => ['get', 'app.members.edit'],
    'update' => ['put', 'app.members.update'],
    'status' => ['put', 'app.members.status'],
    'invite' => ['post', 'app.members.invite'],
]);

/** Which roles may reach which endpoint. Anything absent is forbidden. */
function allowedRolesFor(string $route): array
{
    $readers = [
        MemberRole::Admin,
        MemberRole::Chairperson,
        MemberRole::ViceChairperson,
        MemberRole::Treasurer,
        MemberRole::ViceTreasurer,
    ];

    $writers = [MemberRole::Admin, MemberRole::Chairperson, MemberRole::ViceChairperson];

    return match ($route) {
        'app.members.index', 'app.members.create', 'app.members.show' => $readers,
        default => $writers,
    };
}

it('gates every members endpoint by permission, role by role', function (string $method, string $route) {
    foreach (MemberRole::cases() as $role) {
        // Each role gets the member in the same state: an earlier role that was
        // allowed through would otherwise link a login or move the status, and the
        // next role would be refused for that reason rather than for its permissions.
        Member::whereKey($this->member->id)->update(['user_id' => null, 'status' => MemberStatus::Active]);

        $user = actingAsRole($role);

        $url = str_contains($route, '{member}') || in_array($route, [
            'app.members.show',
            'app.members.edit',
            'app.members.update',
            'app.members.status',
            'app.members.invite',
        ], true)
            ? route($route, $this->member)
            : route($route);

        $response = $this->{$method}($url, $method === 'get' ? [] : payloadFor($route));

        $allowed = in_array($role, allowedRolesFor($route), true);

        expect($response->status() === 403)->toBe(
            ! $allowed,
            sprintf('%s on %s as %s returned %d', $method, $route, $role->value, $response->status()),
        );
    }
})->with('member endpoints');

/** The minimum valid body for each write endpoint, so a 403 is never a 422. */
function payloadFor(string $route): array
{
    return match ($route) {
        'app.members.store' => [
            'full_name' => 'New Member',
            'nrc_number' => '654321/78/9',
            'joined_on' => '2026-01-10',
            'joining_fee_ngwee' => 100_000,
        ],
        'app.members.update' => [
            'full_name' => 'Renamed Member',
            'nrc_number' => '654321/78/9',
        ],
        'app.members.status' => ['status' => MemberStatus::LeftEarly->value],
        'app.members.invite' => ['email' => 'invited@example.com'],
        default => [],
    };
}

it('lets a plain member read their own record but never another member', function () {
    $user = actingAsRole(MemberRole::Member);
    $own = Member::factory()->for($this->cycle)->create(['user_id' => $user->id]);

    $this->get(route('app.members.show', $own))->assertOk();
    $this->get(route('app.members.show', $this->member))->assertForbidden();
});

it('gives a treasurer the register read-only', function () {
    actingAsRole(MemberRole::Treasurer);

    $this->get(route('app.members.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('abilities.create', false)
            ->where('abilities.manage', false)
            ->where('members.data.0.abilities.update', false)
            ->where('members.data.0.abilities.changeStatus', false)
        );

    $this->put(route('app.members.update', $this->member), ['full_name' => 'Nope'])->assertForbidden();
});

it('gives an office holding only reports.view the register read-only too', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('reports.view');

    $this->actingAs($user)->get(route('app.members.index'))->assertOk();
    $this->actingAs($user)->get(route('app.members.create'))->assertOk()
        ->assertInertia(fn ($page) => $page->where('canCreate', false));
    $this->actingAs($user)->put(route('app.members.update', $this->member), [])->assertForbidden();
});
