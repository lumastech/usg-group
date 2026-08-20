<?php

use App\Domain\Cycles\CycleMonthPlanner;
use App\Domain\Savings\SavingsLedger;
use App\Enums\MemberRole;
use App\Models\Cycle;
use App\Models\Member;
use App\Models\User;
use App\Support\Kwacha;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('app.dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('app.dashboard'))->assertOk();
});

it('renders without a cycle rather than failing', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('app.dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('app/Dashboard')->where('overview', null));
});

it('reports the cycle, current month and membership breakdown', function () {
    Carbon::setTestNow('2026-08-19');

    $cycle = Cycle::factory()->create();
    app(CycleMonthPlanner::class)->plan($cycle);
    Member::factory()->count(3)->for($cycle)->create();
    Member::factory()->for($cycle)->expelled()->create();
    Member::factory()->for($cycle)->diaspora()->create();

    $this->actingAs(User::factory()->create());

    $this->get(route('app.dashboard'))->assertInertia(fn ($page) => $page
        ->component('app/Dashboard')
        ->where('overview.cycle.name', '2025–2026')
        ->where('overview.month.label', 'August 2026')
        ->where('overview.month.sequence', 9)
        ->where('overview.month.lockdown_active', false)
        ->where('overview.members.total', 5)
        ->where('overview.members.active', 4)
        ->where('overview.members.expelled', 1)
        ->where('overview.members.diaspora', 1)
    );
});

it('counts down to the final repayment date', function () {
    Carbon::setTestNow('2026-10-08');

    $cycle = Cycle::factory()->create();
    app(CycleMonthPlanner::class)->plan($cycle);

    $this->actingAs(User::factory()->create());

    $this->get(route('app.dashboard'))->assertInertia(fn ($page) => $page
        ->where('overview.cycle.days_to_final_repayment', 30)
        ->where('overview.cycle.deadline_passed', false)
    );
});

it('flags the september lockdown and its savings cap', function () {
    Carbon::setTestNow('2026-09-15');

    $cycle = Cycle::factory()->create();
    app(CycleMonthPlanner::class)->plan($cycle);

    $this->actingAs(User::factory()->create());

    $this->get(route('app.dashboard'))->assertInertia(fn ($page) => $page
        ->where('overview.month.lockdown_active', true)
        ->where('overview.month.savings_cap', 'K500.00')
        ->where('overview.month.registration_open', false)
    );
});

it('totals savings and reports who has not yet saved this month', function () {
    Carbon::setTestNow('2026-08-19');

    $cycle = Cycle::factory()->create();
    $months = app(CycleMonthPlanner::class)->plan($cycle);
    $august = $months->firstWhere('sequence', 9);

    $saver = Member::factory()->for($cycle)->create(['full_name' => 'Has Saved']);
    Member::factory()->for($cycle)->create(['full_name' => 'Still Owing']);

    app(SavingsLedger::class)->record($saver, $august, Kwacha::of(2500), $saver);

    $this->actingAs(User::factory()->create());

    $this->get(route('app.dashboard'))->assertInertia(fn ($page) => $page
        ->where('overview.money.total_savings', 'K2,500.00')
        ->where('overview.money.month_savings', 'K2,500.00')
        ->where('overview.money.members_saved_this_month', 1)
    );
});

it('defers the chase list and loads it on a partial reload', function () {
    Carbon::setTestNow('2026-08-19');

    $cycle = Cycle::factory()->create();
    $months = app(CycleMonthPlanner::class)->plan($cycle);
    $august = $months->firstWhere('sequence', 9);

    $saver = Member::factory()->for($cycle)->create(['full_name' => 'Has Saved']);
    Member::factory()->for($cycle)->create(['full_name' => 'Still Owing']);

    app(SavingsLedger::class)->record($saver, $august, Kwacha::of(2500), $saver);

    $this->actingAs(User::factory()->create());

    // The deferred prop is absent from the first render, then fetched on its own.
    $this->get(route('app.dashboard'))
        ->assertInertia(fn ($page) => $page->missing('membersMissingSavings'));

    $response = $this->get(route('app.dashboard'), [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => Inertia\Inertia::getVersion(),
        'X-Inertia-Partial-Component' => 'app/Dashboard',
        'X-Inertia-Partial-Data' => 'membersMissingSavings',
    ]);

    // A partial reload comes back as an Inertia JSON payload rather than a view.
    $response->assertOk()
        ->assertJsonCount(1, 'props.membersMissingSavings')
        ->assertJsonPath('props.membersMissingSavings.0.full_name', 'Still Owing');
});

it('reports lending from the loan ledger and the social fund as not yet tracked', function () {
    Carbon::setTestNow('2026-08-19');

    $cycle = Cycle::factory()->create();
    app(CycleMonthPlanner::class)->plan($cycle);

    $this->actingAs(User::factory()->create());

    $this->get(route('app.dashboard'))->assertInertia(fn ($page) => $page
        ->where('overview.money.loans_outstanding', 'K0.00')
        ->where('overview.money.social_fund_balance', null)
        ->where('overview.lending.outstanding_ngwee', 0)
        ->where('overview.lending.queue_count', 0)
        ->where('overview.lending.members_penalised_this_month', 0)
    );
});

/*
 * /dashboard is the one URL login knows about. It holds no dashboard of its own —
 * it forwards each user to the portal they work in.
 */
it('sends a committee member to the committee portal', function () {
    $this->seed(RoleSeeder::class);

    $user = User::factory()->create();
    $user->assignRole(MemberRole::Treasurer->value);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(route('app.dashboard'));
});

it('sends an ordinary member to the member portal', function () {
    $this->seed(RoleSeeder::class);

    $user = User::factory()->create();
    $user->assignRole(MemberRole::Member->value);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(route('my.dashboard'));
});

it('sends a user with no role to the member portal', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertRedirect(route('my.dashboard'));
});
