<?php

use App\Domain\Cycles\CycleMonthPlanner;
use App\Domain\Loans\LoanApplicationService;
use App\Domain\Loans\LoanDisbursementQueue;
use App\Domain\Reporting\ShareOutSheet;
use App\Domain\Savings\SavingsLedger;
use App\Enums\MemberRole;
use App\Enums\MemberStatus;
use App\Models\Cycle;
use App\Models\InterestAllocation;
use App\Models\LoanTransaction;
use App\Models\Member;
use App\Models\SavingsTransaction;
use App\Support\Kwacha;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2025-12-01');

    $this->seed(RoleSeeder::class);

    $this->cycle = Cycle::factory()->create();
    $this->months = app(CycleMonthPlanner::class)->plan($this->cycle);
    $this->december = $this->months->firstWhere('sequence', 1);
    $this->january = $this->months->firstWhere('sequence', 2);

    $this->treasurer = memberWithRole($this->cycle, MemberRole::Treasurer);
    $this->chair = memberWithRole($this->cycle, MemberRole::Chairperson);
    $this->saver = memberWithRole($this->cycle);
    $this->borrower = memberWithRole($this->cycle);

    $savings = app(SavingsLedger::class);
    $savings->record($this->saver, $this->december, Kwacha::of(5_000), $this->treasurer);
    $savings->record($this->borrower, $this->december, Kwacha::of(5_000), $this->treasurer);

    $this->credit = function (Member $member, int $kwacha): void {
        InterestAllocation::create([
            'member_id' => $member->id,
            'cycle_month_id' => $this->december->id,
            'method' => $this->december->interest_allocation_method,
            'pool_total_ngwee' => $kwacha * 100,
            'member_basis_ngwee' => 0,
            'pool_basis_ngwee' => 0,
            'amount_ngwee' => $kwacha * 100,
            'residual_ngwee' => 0,
        ]);
    };

    ($this->credit)($this->saver, 300);
    ($this->credit)($this->borrower, 200);

    $loan = app(LoanApplicationService::class)->request(
        $this->borrower,
        Kwacha::of(10_000),
        $this->treasurer,
        Carbon::parse('2025-12-01 09:00'),
    );

    app(LoanApplicationService::class)->approve($loan, $this->chair, $this->treasurer);
    app(LoanDisbursementQueue::class)->disburse($loan->refresh(), $this->december, $this->treasurer);

    $this->sheet = app(ShareOutSheet::class);
});

/*
 * The tie-out. The workbook page the group reads out on the last day has to agree with
 * the ledgers to the ngwee, or a member is handed the wrong envelope — so the totals
 * row is asserted against the ledgers directly rather than against itself.
 */
it('ties the totals row to the ledgers to the ngwee', function () {
    /* The loan column is struck as at today, so stand past the disbursement date. */
    Carbon::setTestNow('2026-01-02');

    $sheet = $this->sheet->for($this->cycle);
    $memberIds = $this->cycle->members()->pluck('id');

    $ledgerSavings = (int) SavingsTransaction::query()
        ->whereIn('member_id', $memberIds)
        ->whereIn('type', ['contribution', 'adjustment', 'import_opening'])
        ->sum('amount_ngwee');

    $ledgerInterest = (int) InterestAllocation::query()
        ->whereIn('member_id', $memberIds)
        ->sum('amount_ngwee');

    $ledgerLoans = (int) LoanTransaction::query()
        ->join('loans', 'loans.id', '=', 'loan_transactions.loan_id')
        ->where('loans.cycle_id', $this->cycle->id)
        ->selectRaw("COALESCE(SUM(CASE WHEN loan_transactions.type IN ('repayment', 'write_off') THEN -loan_transactions.amount_ngwee ELSE loan_transactions.amount_ngwee END), 0) AS balance")
        ->value('balance');

    expect($sheet['totals']['total_savings_ngwee'])->toBe($ledgerSavings)
        ->and($sheet['totals']['total_interest_ngwee'])->toBe($ledgerInterest)
        ->and($sheet['totals']['outstanding_loan_ngwee'])->toBe($ledgerLoans);
});

it('keeps net value = savings + interest − loan on every line and on the footer', function () {
    Carbon::setTestNow('2026-01-02');

    $sheet = $this->sheet->for($this->cycle);

    foreach ($sheet['rows'] as $row) {
        /* Only members who keep their interest carry it into net value. */
        $interest = $row['case'] === 'active_share_out' ? $row['total_interest_ngwee'] : 0;

        expect($row['net_value_ngwee'])
            ->toBe($row['total_savings_ngwee'] + $interest - $row['outstanding_loan_ngwee']);

        expect($row['net_payable_ngwee'])
            ->toBe($row['net_value_ngwee'] + $row['round_off_ngwee']);
    }

    expect($sheet['totals']['net_value_ngwee'])
        ->toBe(array_sum(array_column($sheet['rows'], 'net_value_ngwee')))
        ->and($sheet['totals']['net_payable_ngwee'])
        ->toBe(array_sum(array_column($sheet['rows'], 'net_payable_ngwee')));
});

it('shows the interest a departing member forfeits without paying it', function () {
    $this->saver->forceFill(['status' => MemberStatus::LeftEarly])->save();

    $row = collect($this->sheet->for($this->cycle)['rows'])
        ->firstWhere('member_id', $this->saver->id);

    /* K5,000 saved, K300 earned and shown, none of it carried into net value. */
    expect($row['total_savings_ngwee'])->toBe(500_000)
        ->and($row['total_interest_ngwee'])->toBe(30_000)
        ->and($row['net_value_ngwee'])->toBe(500_000)
        ->and($row['case'])->toBe('left_early');
});

it('separates what is payable from what is a shortfall', function () {
    Carbon::setTestNow('2026-01-02');

    $totals = $this->sheet->for($this->cycle)['totals'];

    /* The borrower is K4,800 under water; nothing is handed over on that line. */
    expect($totals['shortfall_ngwee'])->toBe(480_000)
        ->and($totals['payable_ngwee'])->toBe(
            array_sum(array_map(
                fn (array $row): int => max(0, $row['net_payable_ngwee']),
                $this->sheet->for($this->cycle)['rows'],
            ))
        );
});
