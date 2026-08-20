<?php

use App\Domain\Cycles\CycleMonthPlanner;
use App\Domain\SocialFund\DiasporaApportionmentService;
use App\Domain\SocialFund\SocialFundContributions;
use App\Domain\SocialFund\SocialFundLedger;
use App\Enums\ApportionmentItemStatus;
use App\Enums\MemberRole;
use App\Enums\SocialFundTransactionType;
use App\Exceptions\DomainRuleException;
use App\Exceptions\InsufficientSocialFundException;
use App\Models\Cycle;
use App\Models\Member;
use App\Support\Kwacha;
use Database\Seeders\RoleSeeder;

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->cycle = Cycle::factory()->create();
    app(CycleMonthPlanner::class)->plan($this->cycle);

    $this->treasurer = memberWithRole($this->cycle, MemberRole::Treasurer);
    $this->chair = memberWithRole($this->cycle, MemberRole::Chairperson);

    /* Three members abroad, and enough contributions to pay them. */
    $this->diaspora = Member::factory()->count(3)->for($this->cycle)->create(['is_diaspora' => true]);

    Member::factory()->count(20)->for($this->cycle)->create()
        ->each(fn (Member $payer) => app(SocialFundContributions::class)
            ->record($payer, Kwacha::of(250), $this->treasurer));

    $this->apportionments = app(DiasporaApportionmentService::class);
    $this->ledger = app(SocialFundLedger::class);
});

it('splits the total equally and leaves the remainder in the fund', function () {
    /* K1,000 across three members is K333.33 each, with one ngwee that will not divide. */
    $preview = $this->apportionments->preview($this->cycle, Kwacha::of(1_000));

    expect($preview['share_ngwee'])->toBe(33_333)
        ->and($preview['apportioned_ngwee'])->toBe(99_999)
        ->and($preview['remainder_ngwee'])->toBe(1)
        ->and($preview['recipients'])->toHaveCount(3);
});

it('creates a pending share for each diaspora member without moving money', function () {
    $balanceBefore = Kwacha::toNgwee($this->ledger->balance($this->cycle));

    $apportionment = $this->apportionments->create(
        $this->cycle,
        Kwacha::of(1_000),
        $this->treasurer,
        $this->chair,
    );

    expect($apportionment->items)->toHaveCount(3)
        ->and($apportionment->items->every(fn ($item) => $item->status === ApportionmentItemStatus::Pending))->toBeTrue()
        ->and(Kwacha::toNgwee($apportionment->remainder_ngwee))->toBe(1)
        ->and(Kwacha::toNgwee($this->ledger->balance($this->cycle)))->toBe($balanceBefore);
});

it('debits the fund only when a transfer is confirmed', function () {
    $apportionment = $this->apportionments->create(
        $this->cycle,
        Kwacha::of(1_000),
        $this->treasurer,
        $this->chair,
    );

    $balanceBefore = Kwacha::toNgwee($this->ledger->balance($this->cycle));
    $item = $apportionment->items->first();

    $entry = $this->apportionments->confirmTransfer($item, $this->treasurer, reference: 'MTN-8891');

    expect($entry->type)->toBe(SocialFundTransactionType::DiasporaApportionment)
        ->and(Kwacha::toNgwee($entry->amount_ngwee))->toBe(-33_333)
        ->and($item->fresh()->status)->toBe(ApportionmentItemStatus::Paid)
        ->and($item->fresh()->reference)->toBe('MTN-8891')
        ->and(Kwacha::toNgwee($this->ledger->balance($this->cycle)))->toBe($balanceBefore - 33_333);
});

it('refuses to confirm the same transfer twice', function () {
    $apportionment = $this->apportionments->create(
        $this->cycle,
        Kwacha::of(1_000),
        $this->treasurer,
        $this->chair,
    );

    $item = $apportionment->items->first();
    $this->apportionments->confirmTransfer($item, $this->treasurer);

    $this->apportionments->confirmTransfer($item->fresh(), $this->treasurer);
})->throws(DomainRuleException::class, 'already Paid');

it('refuses a split larger than the fund holds', function () {
    $this->apportionments->create($this->cycle, Kwacha::of(9_000), $this->treasurer, $this->chair);
})->throws(InsufficientSocialFundException::class);

it('refuses a split confirmed by someone who receives a share', function () {
    $abroadChair = memberWithRole($this->cycle, MemberRole::ViceChairperson, ['is_diaspora' => true]);

    $this->apportionments->create($this->cycle, Kwacha::of(400), $this->treasurer, $abroadChair);
})->throws(DomainRuleException::class, 'receives a share of this apportionment');

it('refuses a split when nobody is recorded as living abroad', function () {
    Member::query()->where('is_diaspora', true)->update(['is_diaspora' => false]);

    $this->apportionments->create($this->cycle, Kwacha::of(400), $this->treasurer, $this->chair);
})->throws(DomainRuleException::class, 'living in the diaspora');
