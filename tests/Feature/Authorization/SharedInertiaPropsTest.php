<?php

use App\Domain\Cycles\CycleMonthPlanner;
use App\Enums\MemberRole;
use App\Enums\Permission;
use App\Models\Cycle;
use App\Models\Member;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;

/**
 * Every page in the portal renders from these props, so the shape is a contract:
 * usePermissions(), <Can> and the navigation config all read it directly.
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

it('shares the user with their roles and flattened permissions', function () {
    $user = User::factory()->create();
    $user->assignRole(MemberRole::Treasurer->value);

    $this->actingAs($user)
        ->get(route('my.dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('auth.user.id', $user->id)
            ->where('auth.user.roles', [MemberRole::Treasurer->value])
            ->where('auth.user.permissions', fn ($permissions) => in_array(
                Permission::LoansDisburse->value,
                $permissions->all(),
                true,
            ))
        );
});

it('shares no permissions for a user with no role', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('my.dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('auth.user.roles', [])
            ->where('auth.user.permissions', [])
        );
});

it('links the user to their member record in the current cycle', function () {
    $cycle = Cycle::factory()->create();
    $user = User::factory()->create();
    $member = Member::factory()->for($cycle)->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->get(route('my.dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('auth.user.member_id', $member->id)
            ->where('auth.user.member_number', $member->member_number)
        );
});

it('shares a null member id for a user who is not a member', function () {
    Cycle::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('my.dashboard'))
        ->assertInertia(fn ($page) => $page->where('auth.user.member_id', null));
});

it('shares the current cycle with its key dates', function () {
    Carbon::setTestNow('2026-08-19');

    $cycle = Cycle::factory()->create();
    app(CycleMonthPlanner::class)->plan($cycle);

    $this->actingAs(User::factory()->create())
        ->get(route('my.dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('currentCycle.name', '2025–2026')
            ->where('currentCycle.status', 'active')
            ->where('currentCycle.final_repayment_date', '2026-11-07')
            ->where('currentCycle.min_savings_ngwee', 50_000)
            ->where('currentCycle.savings_increment_ngwee', 50_000)
        );
});

it('shares no cycle when the group has none running', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('my.dashboard'))
        ->assertInertia(fn ($page) => $page->where('currentCycle', null));
});

it('reports the declaration window as open on the 2nd', function () {
    Carbon::setTestNow('2026-08-02 10:00');

    $cycle = Cycle::factory()->create();
    app(CycleMonthPlanner::class)->plan($cycle);

    $this->actingAs(User::factory()->create())
        ->get(route('my.dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('currentCycle.month.window', 'declarations')
            ->where('currentCycle.month.declarations_open', true)
            ->where('currentCycle.month.label', 'August 2026')
        );
});

it('reports the window as trading between the 4th and disbursement', function () {
    Carbon::setTestNow('2026-08-05 10:00');

    $cycle = Cycle::factory()->create();
    app(CycleMonthPlanner::class)->plan($cycle);

    $this->actingAs(User::factory()->create())
        ->get(route('my.dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('currentCycle.month.window', 'trading')
            ->where('currentCycle.month.trading_open', true)
            ->where('currentCycle.month.declarations_open', false)
        );
});

it('reports the window as closed after disbursement', function () {
    Carbon::setTestNow('2026-08-20 10:00');

    $cycle = Cycle::factory()->create();
    app(CycleMonthPlanner::class)->plan($cycle);

    $this->actingAs(User::factory()->create())
        ->get(route('my.dashboard'))
        ->assertInertia(fn ($page) => $page->where('currentCycle.month.window', 'closed'));
});

it('flags lockdown from September so the UI can cap savings', function () {
    Carbon::setTestNow('2026-09-15');

    $cycle = Cycle::factory()->create();
    app(CycleMonthPlanner::class)->plan($cycle);

    $this->actingAs(User::factory()->create())
        ->get(route('my.dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('currentCycle.is_lockdown', true)
            ->where('currentCycle.lockdown_savings_cap_ngwee', 50_000)
        );
});

it('does not flag lockdown before September', function () {
    Carbon::setTestNow('2026-08-15');

    $cycle = Cycle::factory()->create();
    app(CycleMonthPlanner::class)->plan($cycle);

    $this->actingAs(User::factory()->create())
        ->get(route('my.dashboard'))
        ->assertInertia(fn ($page) => $page->where('currentCycle.is_lockdown', false));
});

it('sends money as integer ngwee, never a formatted string', function () {
    Carbon::setTestNow('2026-08-19');

    $cycle = Cycle::factory()->create();
    app(CycleMonthPlanner::class)->plan($cycle);

    $this->actingAs(User::factory()->create())
        ->get(route('my.dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('currentCycle.min_savings_ngwee', fn ($value) => is_int($value))
            ->where('currentCycle.lockdown_savings_cap_ngwee', fn ($value) => is_int($value))
        );
});
