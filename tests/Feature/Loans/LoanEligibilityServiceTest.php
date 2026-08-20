<?php

use App\Domain\Cycles\CycleMonthPlanner;
use App\Domain\Loans\LoanEligibilityService;
use App\Domain\Savings\SavingsLedger;
use App\Enums\LoanStatus;
use App\Enums\MemberStatus;
use App\Models\Cycle;
use App\Models\Loan;
use App\Models\Member;
use App\Support\Kwacha;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->service = app(LoanEligibilityService::class);
    $this->cycle = Cycle::factory()->create();
    $this->months = app(CycleMonthPlanner::class)->plan($this->cycle);
    $this->member = Member::factory()->for($this->cycle)->create();
    $this->treasurer = Member::factory()->for($this->cycle)->create();

    /** Puts savings on the member's ledger, spread across the opening months. */
    $this->save = function (int $kwacha, int $sequence = 1): void {
        app(SavingsLedger::class)->record(
            $this->member,
            $this->months->firstWhere('sequence', $sequence),
            Kwacha::of($kwacha),
            $this->treasurer,
        );
    };
});

it('lets an active member borrow up to twice their savings', function () {
    ($this->save)(5_000);

    $result = $this->service->check($this->member, Kwacha::of(10_000), Carbon::parse('2026-01-02'));

    expect($result->passed())->toBeTrue()
        ->and($result->ceilingNgwee)->toBe(1_000_000)
        ->and($result->cumulativeSavingsNgwee)->toBe(500_000)
        ->and($result->tenor->months)->toBe(4)
        ->and($result->isCompressed())->toBeFalse();
});

it('refuses a single ngwee over the two times savings ceiling', function () {
    ($this->save)(5_000);

    $result = $this->service->check($this->member, Kwacha::ofNgwee(1_000_001), Carbon::parse('2026-01-02'));

    expect($result->failed())->toBeTrue()
        ->and($result->hasReason('exceeds_savings_multiple'))->toBeTrue()
        ->and($result->summary())->toContain('ceiling is K10,000.00');
});

it('refuses a member who has left the group', function () {
    ($this->save)(5_000);
    $this->member->forceFill(['status' => MemberStatus::LeftEarly])->save();

    $result = $this->service->check($this->member->refresh(), Kwacha::of(1_000), Carbon::parse('2026-01-02'));

    expect($result->hasReason('member_not_active'))->toBeTrue();
});

it('allows one loan at a time', function (LoanStatus $status) {
    ($this->save)(5_000);
    Loan::factory()->for($this->cycle)->for($this->member)->create(['status' => $status]);

    $result = $this->service->check($this->member, Kwacha::of(2_000), Carbon::parse('2026-01-02'));

    expect($result->failed())->toBeTrue()
        ->and($result->hasReason('existing_loan'))->toBeTrue()
        ->and($result->hasOpenLoan)->toBeTrue();
})->with(LoanStatus::blocking());

it('ignores a loan that has already been settled or rejected', function (LoanStatus $status) {
    ($this->save)(5_000);
    Loan::factory()->for($this->cycle)->for($this->member)->create(['status' => $status]);

    expect($this->service->check($this->member, Kwacha::of(2_000), Carbon::parse('2026-01-02'))->passed())->toBeTrue();
})->with([LoanStatus::Settled, LoanStatus::Rejected]);

it('lets a committee discretion override the one loan rule', function () {
    ($this->save)(5_000);
    Loan::factory()->for($this->cycle)->for($this->member)->create(['status' => LoanStatus::Repaying]);

    $result = $this->service->check($this->member, Kwacha::of(2_000), Carbon::parse('2026-01-02'), overriding: true);

    expect($result->passed())->toBeTrue()
        ->and($result->overridden)->toBeTrue();
});

it('blocks every new loan from september to the end of the cycle', function (string $date) {
    ($this->save)(5_000);

    $result = $this->service->check($this->member, Kwacha::of(2_000), Carbon::parse($date));

    expect($result->failed())->toBeTrue()
        ->and($result->lockdown)->toBeTrue()
        ->and($result->hasReason('lockdown'))->toBeTrue();
})->with(['2026-09-01', '2026-09-15', '2026-10-04', '2026-11-02']);

it('does not let a discretion override reopen lending during the lockdown', function () {
    ($this->save)(5_000);

    $result = $this->service->check($this->member, Kwacha::of(2_000), Carbon::parse('2026-09-02'), overriding: true);

    expect($result->failed())->toBeTrue()
        ->and($result->hasReason('lockdown'))->toBeTrue();
});

it('compresses the tenor so the schedule ends by the final repayment deadline', function () {
    ($this->save)(5_000, 1);
    ($this->save)(10_000, 2);

    $result = $this->service->check($this->member, Kwacha::of(30_000), Carbon::parse('2026-07-02'));

    expect($result->passed())->toBeTrue()
        ->and($result->earnedTenor->months)->toBe(10)
        ->and($result->tenor->months)->toBe(4)
        ->and($result->isCompressed())->toBeTrue()
        ->and($result->monthsAvailable)->toBe(4);
});

it('reports the eligibility contract the request wizard reads', function () {
    ($this->save)(5_000);

    $payload = $this->service->check($this->member, Kwacha::of(10_000), Carbon::parse('2026-01-02'))->toArray();

    expect($payload)->toHaveKeys([
        'eligible', 'reasons', 'principal_ngwee', 'cumulative_savings_ngwee', 'ceiling_ngwee',
        'tenor_months', 'earned_tenor_months', 'compressed', 'months_available',
        'lockdown', 'has_open_loan', 'overridden',
    ])->and($payload['eligible'])->toBeTrue()
        ->and($payload['ceiling_ngwee'])->toBe(1_000_000);
});
