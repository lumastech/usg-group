<?php

use App\Domain\Cycles\CycleMonthPlanner;
use App\Domain\Loans\InterestEngine;
use App\Domain\Loans\LoanApplicationService;
use App\Domain\Loans\LoanDisbursementQueue;
use App\Domain\Loans\LoanRepaymentService;
use App\Domain\Loans\OutstandingLoanProvider;
use App\Domain\Savings\SavingsLedger;
use App\Domain\SocialFund\LatePenaltyMirror;
use App\Domain\SocialFund\SocialFundContributions;
use App\Domain\SocialFund\SocialFundLedger;
use App\Enums\MemberRole;
use App\Enums\SocialFundTransactionType;
use App\Models\Cycle;
use App\Support\Kwacha;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->cycle = Cycle::factory()->create();
    $this->months = app(CycleMonthPlanner::class)->plan($this->cycle);
    $this->january = $this->months->firstWhere('sequence', 2);
    $this->february = $this->months->firstWhere('sequence', 3);

    $this->borrower = memberWithRole($this->cycle);
    $this->chair = memberWithRole($this->cycle, MemberRole::Chairperson);
    $this->treasurer = memberWithRole($this->cycle, MemberRole::Treasurer);

    app(SavingsLedger::class)->record(
        $this->borrower,
        $this->months->firstWhere('sequence', 1),
        Kwacha::of(5_000),
        $this->treasurer,
    );

    $loan = app(LoanApplicationService::class)->request(
        $this->borrower,
        Kwacha::of(10_000),
        $this->treasurer,
        Carbon::parse('2026-01-02 09:00'),
    );

    app(LoanApplicationService::class)->approve($loan, $this->chair, $this->treasurer);
    $this->loan = app(LoanDisbursementQueue::class)->disburse($loan->refresh(), $this->january, $this->treasurer);

    app(InterestEngine::class)->postFor($this->loan->refresh(), $this->february);

    $this->fund = app(SocialFundLedger::class);
    $this->mirror = app(LatePenaltyMirror::class);
});

/** Three days past February's trading date, which is a K300 penalty. */
function payLate(): void
{
    app(LoanRepaymentService::class)->record(
        test()->loan->refresh(),
        Kwacha::of(1_000),
        test()->treasurer,
        Carbon::parse('2026-02-12'),
    );
}

it('mirrors a late transfer penalty into the fund as an inflow', function () {
    payLate();

    $inflows = $this->fund->entries($this->cycle)
        ->where('type', SocialFundTransactionType::LatePenaltyInflow->value)
        ->get();

    expect($inflows)->toHaveCount(1)
        ->and(Kwacha::toNgwee($inflows->first()->amount_ngwee))->toBe(30_000)
        ->and($inflows->first()->member_id)->toBe($this->borrower->id);
});

it('back-references the loan entry the penalty came from', function () {
    payLate();

    $penalty = $this->loan->transactions()
        ->where('type', 'late_penalty_daily')
        ->first();

    $inflow = $this->mirror->mirrorOf($penalty);

    expect($inflow)->not->toBeNull()
        ->and($inflow->reference_id)->toBe($penalty->id)
        ->and($inflow->reference_type)->toBe($penalty->getMorphClass());
});

it('does not mirror the same penalty twice', function () {
    payLate();

    $penalty = $this->loan->transactions()->where('type', 'late_penalty_daily')->first();

    $this->mirror->mirror($penalty);
    $this->mirror->mirror($penalty);

    expect($this->fund->entries($this->cycle)
        ->where('type', SocialFundTransactionType::LatePenaltyInflow->value)
        ->count())->toBe(1);
});

it('reconciles the loan-side penalties against the fund-side inflows', function () {
    payLate();

    $this->artisan('unity:reconcile-social-fund', ['--cycle' => $this->cycle->id])
        ->assertSuccessful();

    expect(Kwacha::toNgwee($this->mirror->chargedOnLoans($this->cycle->id)))
        ->toBe(Kwacha::toNgwee($this->fund->totalReceived($this->cycle, SocialFundTransactionType::LatePenaltyInflow)));
});

it('names and repairs a penalty that never reached the fund', function () {
    payLate();

    $inflow = $this->fund->entries($this->cycle)
        ->where('type', SocialFundTransactionType::LatePenaltyInflow->value)
        ->first();

    /* The ledger is immutable by design, so simulate the lost mirror at the database. */
    DB::table('social_fund_transactions')->where('id', $inflow->id)->delete();

    $this->artisan('unity:reconcile-social-fund', ['--cycle' => $this->cycle->id])->assertFailed();

    $this->artisan('unity:reconcile-social-fund', ['--cycle' => $this->cycle->id, '--fix' => true])
        ->assertSuccessful();

    expect($this->mirror->unmirrored($this->cycle->id))->toBeEmpty();
});

it('shows the fund contribution on the member summary rebuild', function () {
    app(SocialFundContributions::class)
        ->record($this->borrower, Kwacha::of(250), $this->treasurer, Carbon::parse('2026-02-03'));

    $balance = app(OutstandingLoanProvider::class)
        ->socialFundBalanceFor($this->borrower, $this->february);

    expect(Kwacha::toNgwee($balance))->toBe(25_000);
});
