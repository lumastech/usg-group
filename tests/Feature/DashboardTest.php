<?php

use App\Domain\Cycles\CycleMonthPlanner;
use App\Domain\Savings\SavingsLedger;
use App\Models\Cycle;
use App\Models\Member;
use App\Models\User;
use App\Support\Kwacha;
use Illuminate\Support\Carbon;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('dashboard'))->assertOk();
});

it('renders without a cycle rather than failing', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Dashboard')->where('overview', null));
});

it('reports the cycle, current month and membership breakdown', function () {
    Carbon::setTestNow('2026-08-19');

    $cycle = Cycle::factory()->create();
    app(CycleMonthPlanner::class)->plan($cycle);
    Member::factory()->count(3)->for($cycle)->create();
    Member::factory()->for($cycle)->expelled()->create();
    Member::factory()->for($cycle)->diaspora()->create();

    $this->actingAs(User::factory()->create());

    $this->get(route('dashboard'))->assertInertia(fn ($page) => $page
        ->component('Dashboard')
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

    $this->get(route('dashboard'))->assertInertia(fn ($page) => $page
        ->where('overview.cycle.days_to_final_repayment', 30)
        ->where('overview.cycle.deadline_passed', false)
    );
});

it('flags the september lockdown and its savings cap', function () {
    Carbon::setTestNow('2026-09-15');

    $cycle = Cycle::factory()->create();
    app(CycleMonthPlanner::class)->plan($cycle);

    $this->actingAs(User::factory()->create());

    $this->get(route('dashboard'))->assertInertia(fn ($page) => $page
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

    $this->get(route('dashboard'))->assertInertia(fn ($page) => $page
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
    $this->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->missing('membersMissingSavings'));

    $response = $this->get(route('dashboard'), [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => Inertia\Inertia::getVersion(),
        'X-Inertia-Partial-Component' => 'Dashboard',
        'X-Inertia-Partial-Data' => 'membersMissingSavings',
    ]);

    // A partial reload comes back as an Inertia JSON payload rather than a view.
    $response->assertOk()
        ->assertJsonCount(1, 'props.membersMissingSavings')
        ->assertJsonPath('props.membersMissingSavings.0.full_name', 'Still Owing');
});

it('shows loan and social fund totals as not yet tracked', function () {
    Carbon::setTestNow('2026-08-19');

    $cycle = Cycle::factory()->create();
    app(CycleMonthPlanner::class)->plan($cycle);

    $this->actingAs(User::factory()->create());

    $this->get(route('dashboard'))->assertInertia(fn ($page) => $page
        ->where('overview.money.loans_outstanding', null)
        ->where('overview.money.social_fund_balance', null)
    );
});
