<?php

use App\Domain\Cycles\CurrentCycle;
use App\Domain\Cycles\CycleMonthPlanner;
use App\Domain\Savings\InterestPoolAllocator;
use App\Domain\Savings\SavingsLedger;
use App\Enums\MemberRole;
use App\Models\Cycle;
use App\Models\Member;
use App\Models\SavingsTransaction;
use App\Models\User;
use App\Support\Kwacha;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;

/**
 * The savings screens end to end: who may read the ledger, who may add to it, and
 * what the pages are handed.
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->travelTo(Carbon::parse('2026-01-10'));

    $this->cycle = Cycle::factory()->create();
    $this->months = app(CycleMonthPlanner::class)->plan($this->cycle);
    app(CurrentCycle::class)->set($this->cycle);

    $this->december = $this->months->firstWhere('sequence', 1);
    $this->january = $this->months->firstWhere('sequence', 2);
    $this->september = $this->months->firstWhere('sequence', 10);

    $this->member = Member::factory()->for($this->cycle)->create(['full_name' => 'Bertha Phiri']);
});

/** Signs in as a user holding one role, with a member record of their own. */
function actingAsOffice(MemberRole $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role->value);

    Member::factory()->for(test()->cycle)->create(['user_id' => $user->id]);

    test()->actingAs($user);

    return $user;
}

it('shows the matrix to the offices that may read savings', function (MemberRole $role) {
    actingAsOffice($role);

    $this->get(route('app.savings.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('app/savings/Index'));
})->with([
    MemberRole::Admin,
    MemberRole::Treasurer,
    MemberRole::ViceTreasurer,
    MemberRole::Chairperson,
    MemberRole::ViceChairperson,
]);

it('keeps the matrix from an ordinary member', function () {
    actingAsOffice(MemberRole::Member);

    $this->get(route('app.savings.index'))->assertForbidden();
});

it('lays the matrix out as months against members', function () {
    app(SavingsLedger::class)->record($this->member, $this->december, Kwacha::of(3000), $this->member);
    app(InterestPoolAllocator::class)->allocate($this->december, Kwacha::zero());

    actingAsOffice(MemberRole::Treasurer);

    $this->get(route('app.savings.index'))->assertInertia(fn ($page) => $page
        ->has('matrix.months', 12)
        ->has('matrix.rows', 2)
        ->where('matrix.totals.total_savings_ngwee', 300_000)
        ->where('matrix.totals.total_interest_ngwee', 15_000)
        ->where('rules.increment_ngwee', 50_000)
        ->where('rules.lockdown_cap_ngwee', 50_000)
        ->where('abilities.record', true)
    );
});

it('trims the matrix to the months asked for', function () {
    actingAsOffice(MemberRole::Treasurer);

    $this->get(route('app.savings.index', ['through' => 3]))
        ->assertInertia(fn ($page) => $page->has('matrix.months', 3));
});

it('tells a reader without savings.record that they may not add to the ledger', function () {
    actingAsOffice(MemberRole::Chairperson);

    $this->get(route('app.savings.index'))
        ->assertInertia(fn ($page) => $page->where('abilities.record', false));
});

it('lets a treasurer record a deposit', function () {
    actingAsOffice(MemberRole::Treasurer);

    $this->post(route('app.savings.store'), [
        'member_id' => $this->member->id,
        'cycle_month_id' => $this->december->id,
        'amount_ngwee' => 150_000,
    ])->assertRedirect()->assertSessionHas('success');

    expect((int) SavingsTransaction::where('member_id', $this->member->id)->sum('amount_ngwee'))
        ->toBe(150_000);
});

it('refuses a deposit from an office without savings.record', function (MemberRole $role) {
    actingAsOffice($role);

    $this->post(route('app.savings.store'), [
        'member_id' => $this->member->id,
        'cycle_month_id' => $this->december->id,
        'amount_ngwee' => 150_000,
    ])->assertForbidden();

    expect(SavingsTransaction::count())->toBe(0);
})->with([MemberRole::Chairperson, MemberRole::ViceChairperson, MemberRole::Member]);

it('shows the ledger rule as an error on the amount rather than failing', function (int $ngwee, string $message) {
    actingAsOffice(MemberRole::Treasurer);

    $this->post(route('app.savings.store'), [
        'member_id' => $this->member->id,
        'cycle_month_id' => $this->december->id,
        'amount_ngwee' => $ngwee,
    ])->assertSessionHasErrors(['amount_ngwee' => $message]);

    expect(SavingsTransaction::count())->toBe(0);
})->with([
    'below the minimum' => [40_000, 'Monthly savings must be at least K500.00.'],
    'off the increment' => [75_000, 'Savings must be in increments of K500.00.'],
]);

it('refuses a second deposit that would break the lockdown cap', function () {
    actingAsOffice(MemberRole::Treasurer);

    $this->post(route('app.savings.store'), [
        'member_id' => $this->member->id,
        'cycle_month_id' => $this->september->id,
        'amount_ngwee' => 50_000,
    ])->assertSessionHasNoErrors();

    $this->post(route('app.savings.store'), [
        'member_id' => $this->member->id,
        'cycle_month_id' => $this->september->id,
        'amount_ngwee' => 50_000,
    ])->assertSessionHasErrors('amount_ngwee');

    expect((int) SavingsTransaction::sum('amount_ngwee'))->toBe(50_000);
});

it('refuses a deposit for a member who has left the group', function () {
    actingAsOffice(MemberRole::Treasurer);

    $gone = Member::factory()->for($this->cycle)->expelled()->create();

    $this->post(route('app.savings.store'), [
        'member_id' => $gone->id,
        'cycle_month_id' => $this->december->id,
        'amount_ngwee' => 50_000,
    ])->assertSessionHasErrors('amount_ngwee');
});

it('opens one members history with the entries behind it', function () {
    app(SavingsLedger::class)->record($this->member, $this->december, Kwacha::of(3000), $this->member);

    actingAsOffice(MemberRole::Treasurer);

    $this->get(route('app.savings.show', $this->member))->assertInertia(fn ($page) => $page
        ->component('app/savings/Show')
        ->where('member.full_name', 'Bertha Phiri')
        ->has('history', 12)
        ->where('history.0.savings_ngwee', 300_000)
        ->where('history.0.cumulative_savings_ngwee', 300_000)
        ->where('history.1.cumulative_savings_ngwee', 300_000)
        ->has('transactions.data', 1)
    );
});

it('downloads the ledger as a workbook', function () {
    actingAsOffice(MemberRole::Treasurer);

    $response = $this->get(route('app.savings.export', ['format' => 'xlsx']));

    $response->assertOk();
    expect($response->headers->get('content-disposition'))->toContain('.xlsx');
});

it('downloads the ledger as a pdf', function () {
    app(SavingsLedger::class)->record($this->member, $this->december, Kwacha::of(3000), $this->member);

    actingAsOffice(MemberRole::Treasurer);

    $response = $this->get(route('app.savings.export', ['format' => 'pdf']));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('application/pdf');
});

it('refuses an export format it does not produce', function () {
    actingAsOffice(MemberRole::Treasurer);

    $this->get('/app/savings/export/csv')->assertNotFound();
});

it('keeps the exports from a member with no reporting permission', function () {
    actingAsOffice(MemberRole::Member);

    $this->get(route('app.savings.export', ['format' => 'xlsx']))->assertForbidden();
});
