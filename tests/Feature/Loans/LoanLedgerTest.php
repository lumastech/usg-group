<?php

use App\Domain\Cycles\CycleMonthPlanner;
use App\Domain\Loans\InterestEngine;
use App\Domain\Loans\LedgerInterestIncome;
use App\Domain\Loans\LedgerOutstandingLoans;
use App\Domain\Loans\LoanApplicationService;
use App\Domain\Loans\LoanDisbursementQueue;
use App\Domain\Loans\LoanLedger;
use App\Domain\Loans\LoanRepaymentService;
use App\Domain\Loans\MonthlyInterestIncome;
use App\Domain\Loans\OutstandingLoanProvider;
use App\Domain\Savings\MemberBalanceCalculator;
use App\Domain\Savings\SavingsLedger;
use App\Enums\MemberRole;
use App\Exceptions\ImmutableLedgerException;
use App\Models\Cycle;
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

    $this->borrower = memberWithRole($this->cycle);
    $this->chair = memberWithRole($this->cycle, MemberRole::Chairperson);
    $this->treasurer = memberWithRole($this->cycle, MemberRole::Treasurer);

    app(SavingsLedger::class)->record($this->borrower, $this->december, Kwacha::of(5_000), $this->treasurer);

    $loan = app(LoanApplicationService::class)->request(
        $this->borrower,
        Kwacha::of(10_000),
        $this->treasurer,
        Carbon::parse('2026-01-02 09:00'),
    );

    app(LoanApplicationService::class)->approve($loan, $this->chair, $this->treasurer);

    $this->loan = app(LoanDisbursementQueue::class)->disburse($loan->refresh(), $this->january, $this->treasurer);
    $this->ledger = app(LoanLedger::class);
});

it('keeps the denormalised balance in step with the ledger', function () {
    app(InterestEngine::class)->postFor($this->loan, $this->february);
    app(LoanRepaymentService::class)->record(
        $this->loan->refresh(),
        Kwacha::of(3_000),
        $this->treasurer,
        $this->february->disbursement_on,
    );

    $this->loan->refresh();

    expect($this->loan->current_balance_ngwee->getMinorAmount()->toInt())
        ->toBe($this->ledger->balanceNgwee($this->loan))
        ->toBe(750_000);
});

it('rebuilds a balance that drifted back to what the ledger says', function () {
    app(InterestEngine::class)->postFor($this->loan, $this->february);

    $this->loan->forceFill(['current_balance_ngwee' => 12_345])->save();

    expect($this->ledger->rebuild($this->loan->refresh())->current_balance_ngwee->getMinorAmount()->toInt())
        ->toBe(1_050_000);
});

it('never lets a posted entry be edited', function () {
    $this->loan->transactions()->first()->update(['amount_ngwee' => 1]);
})->throws(ImmutableLedgerException::class, 'cannot be edited');

it('never lets a posted entry be deleted', function () {
    $this->loan->transactions()->first()->delete();
})->throws(ImmutableLedgerException::class, 'cannot be deleted');

it('is the implementation the savings module reads loans through', function () {
    expect(app(OutstandingLoanProvider::class))->toBeInstanceOf(LedgerOutstandingLoans::class)
        ->and(app(MonthlyInterestIncome::class))->toBeInstanceOf(LedgerInterestIncome::class);
});

it('reports the member position the savings snapshots are built from', function () {
    app(InterestEngine::class)->postFor($this->loan, $this->february);
    app(LoanRepaymentService::class)->record(
        $this->loan->refresh(),
        Kwacha::of(3_000),
        $this->treasurer,
        $this->february->disbursement_on,
    );

    $loans = app(OutstandingLoanProvider::class);

    expect(Kwacha::toNgwee($loans->balanceFor($this->borrower, $this->february)))->toBe(750_000)
        ->and(Kwacha::toNgwee($loans->borrowedToDate($this->borrower, $this->february)))->toBe(1_000_000)
        ->and(Kwacha::toNgwee($loans->interestPaidTo($this->borrower, $this->february)))->toBe(50_000)
        ->and(Kwacha::toNgwee($loans->balanceFor($this->borrower, $this->december)))->toBe(0);
});

it('makes the month interest pool the interest actually collected', function () {
    app(InterestEngine::class)->postFor($this->loan, $this->february);

    expect(Kwacha::toNgwee(app(MonthlyInterestIncome::class)->poolFor($this->february)))->toBe(0);

    app(LoanRepaymentService::class)->record(
        $this->loan->refresh(),
        Kwacha::of(3_000),
        $this->treasurer,
        $this->february->disbursement_on,
    );

    expect(Kwacha::toNgwee(app(MonthlyInterestIncome::class)->poolFor($this->february)))->toBe(50_000);
});

it('carries the loan balance into the member month snapshot', function () {
    $snapshot = app(MemberBalanceCalculator::class)->rebuildFor($this->borrower, $this->january);

    expect($snapshot->loan_balance_ngwee->getMinorAmount()->toInt())->toBe(1_000_000)
        ->and($snapshot->borrowed_to_date_ngwee->getMinorAmount()->toInt())->toBe(1_000_000)
        ->and($snapshot->net_value_ngwee->getMinorAmount()->toInt())->toBe(-500_000);
});
