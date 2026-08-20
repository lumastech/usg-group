<?php

use App\Domain\Cycles\CycleMonthPlanner;
use App\Domain\Loans\LoanApplicationService;
use App\Domain\Loans\LoanDisbursementQueue;
use App\Domain\Loans\LoanLedger;
use App\Domain\Payouts\PayoutExecutor;
use App\Domain\Payouts\RoundDownToStep;
use App\Domain\Payouts\RoundingPolicy;
use App\Domain\Savings\SavingsLedger;
use App\Domain\SocialFund\SocialFundContributions;
use App\Domain\SocialFund\SocialFundLedger;
use App\Enums\CycleStatus;
use App\Enums\LoanTransactionType;
use App\Enums\MemberRole;
use App\Enums\MemberStatus;
use App\Enums\PayoutCase;
use App\Enums\SocialFundTransactionType;
use App\Exceptions\DomainRuleException;
use App\Exceptions\MemberLedgersFrozenException;
use App\Models\Cycle;
use App\Models\InterestAllocation;
use App\Models\MemberDebt;
use App\Models\NextOfKin;
use App\Models\NextOfKinRepaymentArrangement;
use App\Models\Payout;
use App\Support\Kwacha;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->cycle = Cycle::factory()->create();
    $this->months = app(CycleMonthPlanner::class)->plan($this->cycle);
    $this->december = $this->months->firstWhere('sequence', 1);
    $this->january = $this->months->firstWhere('sequence', 2);

    $this->treasurer = memberWithRole($this->cycle, MemberRole::Treasurer);
    $this->chair = memberWithRole($this->cycle, MemberRole::Chairperson);
    $this->viceChair = memberWithRole($this->cycle, MemberRole::ViceChairperson);
    $this->member = memberWithRole($this->cycle);

    app(SavingsLedger::class)->record($this->member, $this->december, Kwacha::of(5_000), $this->treasurer);

    InterestAllocation::create([
        'member_id' => $this->member->id,
        'cycle_month_id' => $this->december->id,
        'method' => $this->december->interest_allocation_method,
        'pool_total_ngwee' => 30_000,
        'member_basis_ngwee' => 0,
        'pool_basis_ngwee' => 0,
        'amount_ngwee' => 30_000,
        'residual_ngwee' => 0,
    ]);

    $this->executor = app(PayoutExecutor::class);

    $this->shareOut = function (): void {
        $this->cycle->forceFill(['status' => CycleStatus::ShareOut])->save();
        $this->member->refresh()->load('cycle');
    };

    $this->becomes = function (MemberStatus $status, array $extra = []): void {
        $this->member->forceFill(['status' => $status] + $extra)->save();
        $this->member->refresh()->load('cycle');
    };

    /* K5,000 saved supports a K10,000 loan; disbursed, it puts the member under water. */
    $this->borrow = function (int $kwacha = 10_000) {
        $loan = app(LoanApplicationService::class)->request(
            $this->member,
            Kwacha::of($kwacha),
            $this->treasurer,
            Carbon::parse('2026-01-02 09:00'),
        );

        app(LoanApplicationService::class)->approve($loan, $this->chair, $this->treasurer);

        return app(LoanDisbursementQueue::class)->disburse($loan->refresh(), $this->january, $this->treasurer);
    };

    $this->execute = fn (array $context = []) => $this->executor->execute(
        $this->member->refresh()->load('cycle'),
        $this->treasurer,
        $this->chair,
        $context,
    );
});

it('pays a member out at share-out and closes their ledgers', function () {
    ($this->shareOut)();

    $payout = ($this->execute)();

    expect($payout)->toBeInstanceOf(Payout::class)
        ->and($payout->case)->toBe(PayoutCase::ActiveShareOut)
        ->and(Kwacha::format($payout->amount_ngwee))->toBe('K5,300.00')
        ->and($payout->executed_by_member_id)->toBe($this->treasurer->id)
        ->and($payout->second_approver_member_id)->toBe($this->chair->id)
        ->and($payout->executed_at)->not->toBeNull()
        ->and($this->member->refresh()->ledgersFrozen())->toBeTrue();
});

it('stores the statement it was signed for rather than a figure to be recomputed later', function () {
    ($this->shareOut)();

    $payout = ($this->execute)();
    $labels = collect($payout->breakdown['lines'])->pluck('label');

    expect($labels)->toContain('Total savings', 'Interest earned', 'Net value', 'Net payable')
        ->and($payout->breakdown['net_payable_ngwee'])->toBe(530_000);
});

it('refuses to settle a departure before the cycle reaches share-out', function () {
    ($this->becomes)(MemberStatus::LeftEarly);

    expect(fn () => ($this->execute)())
        ->toThrow(DomainRuleException::class, 'Closures are settled at share-out');

    expect(Payout::count())->toBe(0)
        ->and($this->member->refresh()->ledgersFrozen())->toBeFalse();
});

it('lets the committee settle a death early, but only with a written reason', function () {
    ($this->becomes)(MemberStatus::Deceased, ['date_of_death' => Carbon::parse('2026-01-20')]);

    expect(fn () => ($this->execute)())
        ->toThrow(DomainRuleException::class, 'needs a written reason');

    $payout = ($this->execute)(['early_settlement_note' => 'The family is burying him on Saturday.']);

    expect($payout->wasSettledEarly())->toBeTrue()
        ->and($payout->early_settlement_note)->toContain('burying him')
        ->and($this->cycle->refresh()->status)->toBe(CycleStatus::Active);
});

it('records a debt instead of paying a negative amount when a member left owing', function () {
    ($this->borrow)();
    ($this->becomes)(MemberStatus::LeftEarly);
    ($this->shareOut)();

    $debt = ($this->execute)();

    // K5,000 saved, interest forfeited, less the K10,000 owed.
    expect($debt)->toBeInstanceOf(MemberDebt::class)
        ->and(Kwacha::format($debt->amount_owed_ngwee))->toBe('K5,000.00')
        ->and($debt->case)->toBe(PayoutCase::LeftEarly)
        ->and(Payout::count())->toBe(0)
        ->and($this->member->refresh()->ledgersFrozen())->toBeTrue();
});

it('turns a deceased member\'s shortfall into a next-of-kin arrangement, never a payment', function () {
    $kin = NextOfKin::factory()->create(['member_id' => $this->member->id]);

    ($this->borrow)();
    ($this->becomes)(MemberStatus::Deceased, ['date_of_death' => Carbon::parse('2026-01-20')]);
    ($this->shareOut)();

    expect(fn () => ($this->execute)())
        ->toThrow(DomainRuleException::class, 'Record the terms the next of kin has agreed to');

    $arrangement = ($this->execute)([
        'agreed_terms' => 'K1,000 a month from the estate for five months.',
        'next_of_kin_id' => $kin->id,
    ]);

    expect($arrangement)->toBeInstanceOf(NextOfKinRepaymentArrangement::class)
        ->and(Kwacha::format($arrangement->amount_owed_ngwee))->toBe('K4,700.00')
        ->and($arrangement->next_of_kin_id)->toBe($kin->id)
        ->and(Payout::count())->toBe(0);
});

it('refuses a next of kin who is not one of this member\'s nominees', function () {
    $stranger = NextOfKin::factory()->create([
        'member_id' => memberWithRole($this->cycle)->id,
    ]);

    ($this->borrow)();
    ($this->becomes)(MemberStatus::Deceased, ['date_of_death' => Carbon::parse('2026-01-20')]);
    ($this->shareOut)();

    expect(fn () => ($this->execute)(['agreed_terms' => 'Monthly.', 'next_of_kin_id' => $stranger->id]))
        ->toThrow(DomainRuleException::class, 'not one of');
});

it('needs two different committee members, neither of them the member being settled', function () {
    ($this->shareOut)();

    expect(fn () => $this->executor->execute($this->member, $this->treasurer, $this->treasurer))
        ->toThrow(DomainRuleException::class, 'second, different committee member');

    expect(fn () => $this->executor->execute($this->member, $this->treasurer, $this->member))
        ->toThrow(DomainRuleException::class, 'does not sit on the committee');

    $committeeMember = memberWithRole($this->cycle, MemberRole::ViceTreasurer, ['status' => MemberStatus::Active]);

    expect(fn () => $this->executor->execute(
        $committeeMember->load('cycle'),
        $committeeMember,
        $this->chair,
    ))->toThrow(DomainRuleException::class, 'cannot stand as an approver on their own request');
});

it('settles a member once', function () {
    ($this->shareOut)();
    ($this->execute)();

    expect(fn () => ($this->execute)())
        ->toThrow(DomainRuleException::class, 'already been settled');

    expect(Payout::count())->toBe(1);
});

it('freezes the savings, loan and fund ledgers the moment a payout is executed', function () {
    $loan = ($this->borrow)(2_000);
    ($this->shareOut)();
    ($this->execute)();

    $frozen = $this->member->refresh();

    expect(fn () => app(SavingsLedger::class)->record($frozen, $this->january, Kwacha::of(500), $this->treasurer))
        ->toThrow(MemberLedgersFrozenException::class, 'ledgers are closed');

    expect(fn () => app(LoanLedger::class)->post(
        $loan->refresh(),
        LoanTransactionType::Repayment,
        Kwacha::of(500),
        Carbon::parse('2026-02-10'),
    ))->toThrow(MemberLedgersFrozenException::class);

    expect(fn () => app(SocialFundContributions::class)
        ->record($frozen, Kwacha::of(250), $this->treasurer))
        ->toThrow(DomainRuleException::class);
});

it('leaves the social fund alone when no rounding convention is bound', function () {
    ($this->shareOut)();
    ($this->execute)();

    expect(app(SocialFundLedger::class)
        ->entries($this->cycle)
        ->where('type', SocialFundTransactionType::Adjustment->value)
        ->count())->toBe(0);
});

it('sends the round-off remainder to the social fund once a convention is bound', function () {
    app()->instance(RoundingPolicy::class, new RoundDownToStep(5_000));

    /* K7 more interest puts the position off a K50 boundary: K5,307. */
    InterestAllocation::create([
        'member_id' => $this->member->id,
        'cycle_month_id' => $this->january->id,
        'method' => $this->january->interest_allocation_method,
        'pool_total_ngwee' => 700,
        'member_basis_ngwee' => 0,
        'pool_basis_ngwee' => 0,
        'amount_ngwee' => 700,
        'residual_ngwee' => 0,
    ]);

    ($this->shareOut)();

    $payout = app(PayoutExecutor::class)->execute(
        $this->member->refresh()->load('cycle'),
        $this->treasurer,
        $this->chair,
    );

    $remainder = app(SocialFundLedger::class)
        ->entries($this->cycle)
        ->where('type', SocialFundTransactionType::Adjustment->value)
        ->sum('amount_ngwee');

    expect(Kwacha::format($payout->net_value_ngwee))->toBe('K5,307.00')
        ->and(Kwacha::format($payout->amount_ngwee))->toBe('K5,300.00')
        ->and(Kwacha::format((int) $remainder))->toBe('K7.00');
});
