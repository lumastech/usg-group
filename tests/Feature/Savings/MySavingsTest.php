<?php

use App\Domain\Cycles\CurrentCycle;
use App\Domain\Cycles\CycleMonthPlanner;
use App\Domain\Savings\InterestPoolAllocator;
use App\Domain\Savings\SavingsLedger;
use App\Models\Cycle;
use App\Models\Member;
use App\Models\User;
use App\Support\Kwacha;
use Illuminate\Support\Carbon;

/**
 * The member portal shows a member themselves and nobody else. There is no member id
 * in the URL, so the scoping is structural rather than a check that could be missed.
 */
beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-01-10'));

    $this->cycle = Cycle::factory()->create();
    $this->months = app(CycleMonthPlanner::class)->plan($this->cycle);
    app(CurrentCycle::class)->set($this->cycle);

    $this->december = $this->months->firstWhere('sequence', 1);

    $this->user = User::factory()->create();
    $this->member = Member::factory()->for($this->cycle)->create([
        'user_id' => $this->user->id,
        'full_name' => 'Bertha Phiri',
    ]);

    $this->other = Member::factory()->for($this->cycle)->create(['full_name' => 'Someone Else']);
});

it('shows a member their own savings', function () {
    app(SavingsLedger::class)->record($this->member, $this->december, Kwacha::of(3000), $this->member);
    app(InterestPoolAllocator::class)->allocate($this->december, Kwacha::zero());

    $this->actingAs($this->user)
        ->get(route('my.savings'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('my/Savings')
            ->where('member.full_name', 'Bertha Phiri')
            ->where('totals.savings_ngwee', 300_000)
            ->where('totals.interest_ngwee', 15_000)
            ->where('totals.net_value_ngwee', 315_000)
            ->has('history', 12)
            ->has('transactions', 1)
        );
});

it('never carries another members entries', function () {
    app(SavingsLedger::class)->record($this->other, $this->december, Kwacha::of(9000), $this->other);

    $this->actingAs($this->user)
        ->get(route('my.savings'))
        ->assertInertia(fn ($page) => $page
            ->where('totals.savings_ngwee', 0)
            ->has('transactions', 0)
        );
});

it('explains itself to a login with no member record', function () {
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->get(route('my.savings'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('member', null)->where('totals', null));
});

it('hands the member their statement as a pdf', function () {
    app(SavingsLedger::class)->record($this->member, $this->december, Kwacha::of(3000), $this->member);

    $response = $this->actingAs($this->user)->get(route('my.savings.statement'));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('application/pdf')
        ->and($response->headers->get('content-disposition'))
        ->toContain("savings-statement-{$this->member->member_number}");
});

it('has no statement to give a login with no member record', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('my.savings.statement'))
        ->assertNotFound();
});

it('sends a guest to the login page', function () {
    $this->get(route('my.savings'))->assertRedirect(route('login'));
});
