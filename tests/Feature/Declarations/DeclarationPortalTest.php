<?php

use App\Domain\Cycles\CurrentCycle;
use App\Domain\Cycles\CycleMonthPlanner;
use App\Domain\Declarations\DeclarationService;
use App\Domain\Declarations\DeclarationSheet;
use App\Domain\Savings\SavingsLedger;
use App\Enums\DeclarationStatus;
use App\Enums\MemberRole;
use App\Models\Cycle;
use App\Models\Declaration;
use App\Models\Member;
use App\Models\User;
use App\Support\Kwacha;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;

/**
 * The declaration screens end to end: who may declare, who may capture for somebody
 * else, and what each page is handed.
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);
    /* Inside the January window, so the member portal accepts a submission. */
    $this->travelTo(Carbon::parse('2026-01-02 10:00'));

    $this->cycle = Cycle::factory()->create();
    $this->months = app(CycleMonthPlanner::class)->plan($this->cycle);
    app(CurrentCycle::class)->set($this->cycle);

    $this->january = $this->months->firstWhere('sequence', 2);
});

/** Signs in as a user holding one role, with a member record of their own. */
function declaringAs(MemberRole $role): Member
{
    $user = User::factory()->create();
    $user->assignRole($role->value);

    $member = Member::factory()->for(test()->cycle)->create(['user_id' => $user->id]);

    test()->actingAs($user);

    return $member;
}

it('shows a member their own declaration form', function () {
    declaringAs(MemberRole::Member);

    $this->get(route('my.declarations'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('my/Declarations')
            ->where('month.window', 'declarations')
            ->where('defaults.saving_amount_ngwee', 50_000)
            ->where('abilities.submit', true));
});

it('lets a member submit their own declaration', function () {
    $member = declaringAs(MemberRole::Member);

    $this->post(route('my.declarations.store'), [
        'cycle_month_id' => $this->january->id,
        'saving_amount_ngwee' => 100_000,
        'loan_repayment_amount_ngwee' => 0,
        'loan_requested_amount_ngwee' => 0,
    ])->assertRedirect();

    expect(Declaration::query()->where('member_id', $member->id)->first())
        ->not->toBeNull()
        ->status->toBe(DeclarationStatus::Submitted);
});

it('returns the savings rule as a field error rather than an error page', function () {
    declaringAs(MemberRole::Member);

    $this->from(route('my.declarations'))
        ->post(route('my.declarations.store'), [
            'cycle_month_id' => $this->january->id,
            'saving_amount_ngwee' => 75_000,
            'loan_repayment_amount_ngwee' => 0,
            'loan_requested_amount_ngwee' => 0,
        ])
        ->assertRedirect(route('my.declarations'))
        ->assertSessionHasErrors('saving_amount_ngwee');
});

it('refuses a member submitting outside the window', function () {
    declaringAs(MemberRole::Member);
    $this->travelTo(Carbon::parse('2026-01-05 10:00'));

    $this->from(route('my.declarations'))
        ->post(route('my.declarations.store'), [
            'cycle_month_id' => $this->january->id,
            'saving_amount_ngwee' => 50_000,
            'loan_repayment_amount_ngwee' => 0,
            'loan_requested_amount_ngwee' => 0,
        ])
        ->assertSessionHasErrors('saving_amount_ngwee');

    expect(Declaration::query()->count())->toBe(0);
});

it('shows the committee the month\'s sheet with the missing members beside it', function (MemberRole $role) {
    declaringAs($role);
    $silent = Member::factory()->for($this->cycle)->create(['full_name' => 'Bertha Phiri']);

    $this->get(route('app.declarations.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('app/declarations/Index')
            ->has('sheet.rows')
            ->where('missing.0.full_name', fn ($name): bool => is_string($name)));
})->with([MemberRole::Admin, MemberRole::Treasurer, MemberRole::Chairperson]);

it('keeps a plain member out of the committee declarations screen', function () {
    declaringAs(MemberRole::Member);

    $this->get(route('app.declarations.index'))->assertForbidden();
});

it('lets the treasurer capture a late declaration for a member', function () {
    declaringAs(MemberRole::Treasurer);
    $this->travelTo(Carbon::parse('2026-01-06 09:00'));

    $member = Member::factory()->for($this->cycle)->create();

    $this->post(route('app.declarations.store'), [
        'member_id' => $member->id,
        'cycle_month_id' => $this->january->id,
        'saving_amount_ngwee' => 50_000,
        'loan_repayment_amount_ngwee' => 0,
        'loan_requested_amount_ngwee' => 0,
    ])->assertRedirect();

    expect(Declaration::query()->where('member_id', $member->id)->first()->is_late)->toBeTrue();
});

it('does not let the chair capture a declaration for somebody else', function () {
    declaringAs(MemberRole::Chairperson);
    $member = Member::factory()->for($this->cycle)->create();

    $this->post(route('app.declarations.store'), [
        'member_id' => $member->id,
        'cycle_month_id' => $this->january->id,
        'saving_amount_ngwee' => 50_000,
        'loan_repayment_amount_ngwee' => 0,
        'loan_requested_amount_ngwee' => 0,
    ])->assertForbidden();
});

it('exports the month\'s declarations with negative totals intact', function () {
    $treasurer = declaringAs(MemberRole::Treasurer);
    $borrower = Member::factory()->for($this->cycle)->create();

    app(SavingsLedger::class)
        ->record($borrower, $this->months->firstWhere('sequence', 1), Kwacha::of(5_000), $treasurer);

    app(DeclarationService::class)->submit(
        $borrower,
        $this->january,
        Kwacha::of(500),
        Kwacha::zero(),
        Kwacha::of(5_000),
        actor: $borrower,
    );

    $sheet = app(DeclarationSheet::class)->for($this->january);
    $row = collect($sheet['rows'])->firstWhere('member_id', $borrower->id);

    expect($row['total_ngwee'])->toBe(-450_000)
        ->and($sheet['totals']['total_ngwee'])->toBe(-450_000);

    $this->get(route('app.declarations.export', ['format' => 'xlsx', 'month' => 2]))->assertOk();
});
