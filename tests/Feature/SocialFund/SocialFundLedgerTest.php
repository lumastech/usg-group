<?php

use App\Domain\Cycles\CycleMonthPlanner;
use App\Domain\SocialFund\SocialFundContributions;
use App\Domain\SocialFund\SocialFundLedger;
use App\Enums\MemberRole;
use App\Enums\SocialFundTransactionType;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ImmutableLedgerException;
use App\Exceptions\InsufficientSocialFundException;
use App\Models\Cycle;
use App\Support\Kwacha;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->cycle = Cycle::factory()->create();
    $this->months = app(CycleMonthPlanner::class)->plan($this->cycle);

    $this->treasurer = memberWithRole($this->cycle, MemberRole::Treasurer);
    $this->chair = memberWithRole($this->cycle, MemberRole::Chairperson);
    $this->member = memberWithRole($this->cycle);

    $this->ledger = app(SocialFundLedger::class);

    /* Something for the fund to pay out of. */
    foreach ([$this->member, $this->treasurer, $this->chair] as $payer) {
        app(SocialFundContributions::class)->record($payer, Kwacha::of(250), $this->treasurer);
    }
});

it('refuses an outflow with no second approver', function () {
    $this->ledger->post(
        $this->cycle,
        SocialFundTransactionType::GatheringExpense,
        Kwacha::of(-100),
        Carbon::today(),
        actor: $this->treasurer,
    );
})->throws(DomainRuleException::class, 'second committee member');

it('refuses an outflow confirmed by the same person twice', function () {
    $this->ledger->pay(
        $this->cycle,
        SocialFundTransactionType::GatheringExpense,
        Kwacha::of(100),
        Carbon::today(),
        $this->treasurer,
        $this->treasurer,
    );
})->throws(DomainRuleException::class);

it('refuses an outflow confirmed by someone off the committee', function () {
    $this->ledger->pay(
        $this->cycle,
        SocialFundTransactionType::GatheringExpense,
        Kwacha::of(100),
        Carbon::today(),
        $this->treasurer,
        $this->member,
    );
})->throws(DomainRuleException::class, 'does not sit on the committee');

it('stores an outflow as a negative entry and reduces the balance', function () {
    $entry = $this->ledger->pay(
        $this->cycle,
        SocialFundTransactionType::GatheringExpense,
        Kwacha::of(100),
        Carbon::today(),
        $this->treasurer,
        $this->chair,
    );

    expect(Kwacha::toNgwee($entry->amount_ngwee))->toBe(-10_000)
        ->and(Kwacha::toNgwee($this->ledger->balance($this->cycle)))->toBe(65_000);
});

it('never lets the fund go negative', function () {
    $this->ledger->pay(
        $this->cycle,
        SocialFundTransactionType::GatheringExpense,
        Kwacha::of(800),
        Carbon::today(),
        $this->treasurer,
        $this->chair,
    );
})->throws(InsufficientSocialFundException::class);

it('holds a negative adjustment to the same two-person rule as a grant', function () {
    $this->ledger->post(
        $this->cycle,
        SocialFundTransactionType::Adjustment,
        Kwacha::of(-50),
        Carbon::today(),
        actor: $this->treasurer,
    );
})->throws(DomainRuleException::class, 'second committee member');

it('lets a positive adjustment through without a second signature', function () {
    $this->ledger->post(
        $this->cycle,
        SocialFundTransactionType::Adjustment,
        Kwacha::of(50),
        Carbon::today(),
        actor: $this->treasurer,
    );

    expect(Kwacha::toNgwee($this->ledger->balance($this->cycle)))->toBe(80_000);
});

it('refuses a zero entry', function () {
    $this->ledger->post(
        $this->cycle,
        SocialFundTransactionType::Adjustment,
        Kwacha::of(0),
        Carbon::today(),
        actor: $this->treasurer,
    );
})->throws(DomainRuleException::class, 'non-zero');

it('keeps the ledger append-only', function () {
    $entry = $this->ledger->entries($this->cycle)->first();

    expect(fn () => $entry->update(['note' => 'edited']))
        ->toThrow(ImmutableLedgerException::class)
        ->and(fn () => $entry->delete())
        ->toThrow(ImmutableLedgerException::class);
});

it('files each entry against the cycle month its date falls in', function () {
    $entry = $this->ledger->entries($this->cycle)->first();

    expect($entry->cycle_month_id)->not->toBeNull();
});
