<?php

use App\Domain\Cycles\CycleMonthPlanner;
use App\Domain\Loans\LoanApplicationService;
use App\Domain\Loans\LoanDisbursementQueue;
use App\Domain\Loans\LoanRepaymentService;
use App\Domain\Payouts\PayoutCalculator;
use App\Domain\Payouts\RoundDownToStep;
use App\Domain\Payouts\RoundingPolicy;
use App\Domain\Savings\SavingsLedger;
use App\Enums\MemberRole;
use App\Enums\MemberStatus;
use App\Enums\PayoutCase;
use App\Enums\PayoutLineKind;
use App\Exceptions\DomainRuleException;
use App\Models\Cycle;
use App\Models\InterestAllocation;
use App\Models\Member;
use App\Support\Kwacha;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->cycle = Cycle::factory()->create();
    $this->months = app(CycleMonthPlanner::class)->plan($this->cycle);
    $this->december = $this->months->firstWhere('sequence', 1);
    $this->january = $this->months->firstWhere('sequence', 2);
    $this->february = $this->months->firstWhere('sequence', 3);

    $this->treasurer = memberWithRole($this->cycle, MemberRole::Treasurer);
    $this->chair = memberWithRole($this->cycle, MemberRole::Chairperson);
    $this->member = memberWithRole($this->cycle);

    /* K5,000 in December and K1,000 in January: K6,000 saved. */
    app(SavingsLedger::class)->record($this->member, $this->december, Kwacha::of(5_000), $this->treasurer);
    app(SavingsLedger::class)->record($this->member, $this->january, Kwacha::of(1_000), $this->treasurer);

    /* Interest credited at each month end: K300, then K200. */
    $this->credit = function ($month, int $kwacha): void {
        InterestAllocation::create([
            'member_id' => $this->member->id,
            'cycle_month_id' => $month->id,
            'method' => $month->interest_allocation_method,
            'pool_total_ngwee' => $kwacha * 100,
            'member_basis_ngwee' => 0,
            'pool_basis_ngwee' => 0,
            'amount_ngwee' => $kwacha * 100,
            'residual_ngwee' => 0,
        ]);
    };

    ($this->credit)($this->december, 300);
    ($this->credit)($this->january, 200);

    $this->calculator = app(PayoutCalculator::class);

    /* Amount by label, so a worked example reads as the statement does. */
    $this->line = fn ($breakdown, string $needle) => collect($breakdown->lines)
        ->first(fn ($line): bool => str_contains($line->label, $needle));

    $this->becomes = function (MemberStatus $status, array $extra = []): Member {
        $this->member->forceFill(['status' => $status] + $extra)->save();

        return $this->member->refresh();
    };

    /* K5,000 saved supports a K10,000 loan under the 2× rule. */
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
});

it('pays an active member their savings plus every ngwee of interest at share-out', function () {
    $breakdown = $this->calculator->for($this->member);

    expect($breakdown->case)->toBe(PayoutCase::ActiveShareOut)
        ->and(Kwacha::format(($this->line)($breakdown, 'Total savings')->amountNgwee))->toBe('K6,000.00')
        ->and(Kwacha::format(($this->line)($breakdown, 'Interest earned')->amountNgwee))->toBe('K500.00')
        ->and(Kwacha::format(($this->line)($breakdown, 'Member value')->amountNgwee))->toBe('K6,500.00')
        ->and(Kwacha::format($breakdown->netValueNgwee))->toBe('K6,500.00')
        ->and(Kwacha::format($breakdown->netPayableNgwee))->toBe('K6,500.00')
        ->and($breakdown->isNegative())->toBeFalse();
});

it('subtracts what an active member still owes on their loan', function () {
    ($this->borrow)();

    $breakdown = $this->calculator->for($this->member);

    // K6,000 saved + K500 interest − K10,000 owed = −K3,500.
    expect(Kwacha::format(($this->line)($breakdown, 'Outstanding loan')->amountNgwee))->toBe('-K10,000.00')
        ->and(Kwacha::format($breakdown->netValueNgwee))->toBe('-K3,500.00')
        ->and($breakdown->isNegative())->toBeTrue()
        ->and(Kwacha::format($breakdown->shortfallNgwee()))->toBe('K3,500.00')
        ->and($breakdown->payableNgwee())->toBe(0);
});

it('leaves the interest with the group when a member left early', function () {
    ($this->becomes)(MemberStatus::LeftEarly);

    $breakdown = $this->calculator->for($this->member);
    $forfeited = ($this->line)($breakdown, 'Interest forfeited');

    expect($breakdown->case)->toBe(PayoutCase::LeftEarly)
        ->and(Kwacha::format($breakdown->netPayableNgwee))->toBe('K6,000.00')
        ->and($forfeited->kind)->toBe(PayoutLineKind::Note)
        ->and($forfeited->counts())->toBeFalse()
        ->and($forfeited->formula)->toContain('K500.00');
});

it('pays an expelled member their savings only, loan still deducted', function () {
    ($this->borrow)(2_000);
    ($this->becomes)(MemberStatus::Expelled, ['expulsion_ground' => 'loan_misconduct']);

    $breakdown = $this->calculator->for($this->member);

    // K6,000 saved, no interest, less the K2,000 still owed.
    expect($breakdown->case)->toBe(PayoutCase::Expelled)
        ->and(Kwacha::format($breakdown->netPayableNgwee))->toBe('K4,000.00');
});

it('stops a deceased member\'s interest at the month that had closed by the date of death', function () {
    ($this->credit)($this->february, 700);

    ($this->becomes)(MemberStatus::Deceased, ['date_of_death' => Carbon::parse('2026-01-20')]);

    $breakdown = $this->calculator->for($this->member);

    // December closed before 20 January and counts; January had not, and does not.
    expect(Kwacha::format(($this->line)($breakdown, 'Interest earned to')->amountNgwee))->toBe('K300.00')
        ->and(Kwacha::format($breakdown->netPayableNgwee))->toBe('K6,300.00')
        ->and($breakdown->interestCutoff->toDateString())->toBe('2026-01-20');
});

it('strikes a deceased member\'s loan on the day they died, not at month end', function () {
    $loan = ($this->borrow)();

    ($this->becomes)(MemberStatus::Deceased, ['date_of_death' => Carbon::parse('2026-01-20')]);

    /* A repayment banked after the death cannot change what the estate is owed. */
    app(LoanRepaymentService::class)->record(
        $loan->refresh(),
        Kwacha::of(4_000),
        $this->treasurer,
        Carbon::parse('2026-01-25'),
    );

    $breakdown = $this->calculator->for($this->member->refresh());

    expect(Kwacha::format(($this->line)($breakdown, 'Outstanding loan at')->amountNgwee))->toBe('-K10,000.00')
        ->and(Kwacha::format($breakdown->netPayableNgwee))->toBe('-K3,700.00');
});

it('records a deceased member with no date of death as uncomputable rather than guessing one', function () {
    $this->member->forceFill(['status' => MemberStatus::Deceased, 'date_of_death' => null])->save();

    expect(fn () => $this->calculator->for($this->member->refresh()))
        ->toThrow(DomainRuleException::class, 'without a date of death');
});

it('refuses a case that does not match the member\'s status', function () {
    expect(fn () => $this->calculator->using($this->member, PayoutCase::Deceased))
        ->toThrow(DomainRuleException::class, 'is Active, so their closure is settled as Share-out');

    ($this->becomes)(MemberStatus::LeftEarly);

    expect(fn () => $this->calculator->using($this->member, PayoutCase::ActiveShareOut))
        ->toThrow(DomainRuleException::class, 'not as Share-out');
});

it('applies no round-off, so net payable is the net value to the ngwee', function () {
    app(SavingsLedger::class)->record($this->member, $this->february, Kwacha::of(500), $this->treasurer);
    ($this->credit)($this->february, 7);

    $breakdown = $this->calculator->for($this->member);
    $adjustment = ($this->line)($breakdown, 'Round-off adjustment');

    expect($adjustment->amountNgwee)->toBe(0)
        ->and($adjustment->formula)->toContain('No round-off applied')
        ->and($breakdown->netPayableNgwee)->toBe($breakdown->netValueNgwee)
        ->and(Kwacha::format($breakdown->netPayableNgwee))->toBe('K7,007.00');
});

it('shaves the payment down to whole notes once a rounding convention is bound', function () {
    app()->instance(RoundingPolicy::class, new RoundDownToStep(5_000));

    ($this->credit)($this->february, 7);

    $breakdown = app(PayoutCalculator::class)->for($this->member);

    // K6,507 rounds down to K6,500; the K7 difference is the adjustment.
    expect(Kwacha::format($breakdown->netValueNgwee))->toBe('K6,507.00')
        ->and(Kwacha::format($breakdown->roundOffNgwee))->toBe('-K7.00')
        ->and(Kwacha::format($breakdown->netPayableNgwee))->toBe('K6,500.00');
});

it('never rounds a member who is under water', function () {
    app()->instance(RoundingPolicy::class, new RoundDownToStep(5_000));

    ($this->borrow)();

    $breakdown = app(PayoutCalculator::class)->for($this->member);

    expect($breakdown->roundOffNgwee)->toBe(0)
        ->and(Kwacha::format($breakdown->netPayableNgwee))->toBe('-K3,500.00');
});

it('adds up: the counting lines sum to the net payable', function () {
    ($this->borrow)(2_000);

    $breakdown = $this->calculator->for($this->member);

    $sum = collect($breakdown->countingLines())->sum(fn ($line): int => $line->amountNgwee);

    expect($sum)->toBe($breakdown->netPayableNgwee);
});
