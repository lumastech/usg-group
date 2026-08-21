<?php

use App\Domain\Cycles\CurrentCycle;
use App\Domain\Cycles\CycleMonthPlanner;
use App\Domain\Loans\LoanApplicationService;
use App\Domain\Loans\LoanDisbursementQueue;
use App\Domain\Savings\SavingsLedger;
use App\Enums\CollateralClaimStatus;
use App\Enums\LoanStatus;
use App\Enums\MemberRole;
use App\Models\Cycle;
use App\Models\Loan;
use App\Support\Kwacha;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;

/**
 * The loan screens end to end: who may read the register, who may move a loan along,
 * and what each page is handed.
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->travelTo(Carbon::parse('2026-01-07 09:00'));

    $this->cycle = Cycle::factory()->create();
    $this->months = app(CycleMonthPlanner::class)->plan($this->cycle);
    app(CurrentCycle::class)->set($this->cycle);

    $this->december = $this->months->firstWhere('sequence', 1);
    $this->january = $this->months->firstWhere('sequence', 2);

    $this->borrower = memberWithRole($this->cycle, MemberRole::Member, ['full_name' => 'Bertha Phiri']);
    $this->chair = memberWithRole($this->cycle, MemberRole::Chairperson);
    $this->viceChair = memberWithRole($this->cycle, MemberRole::ViceChairperson);
    $this->treasurer = memberWithRole($this->cycle, MemberRole::Treasurer);

    app(SavingsLedger::class)->record($this->borrower, $this->december, Kwacha::of(5_000), $this->treasurer);

    $this->requested = fn (): Loan => app(LoanApplicationService::class)->request(
        $this->borrower,
        Kwacha::of(10_000),
        $this->treasurer,
        Carbon::parse('2026-01-02 09:00'),
    );

    $this->approved = function (): Loan {
        $loan = ($this->requested)();

        return app(LoanApplicationService::class)->approve($loan, $this->chair, $this->treasurer);
    };
});

it('shows the register to every office that may read lending', function (MemberRole $role) {
    $this->actingAs(memberWithRole($this->cycle, $role)->user);

    $this->get(route('app.loans.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('app/loans/Index')->has('tabs'));
})->with([MemberRole::Admin, MemberRole::Chairperson, MemberRole::Treasurer]);

it('keeps the register away from an ordinary member', function () {
    $this->actingAs($this->borrower->user);

    $this->get(route('app.loans.index'))->assertForbidden();
});

it('answers the eligibility endpoint with the contract the wizard reads', function () {
    $this->actingAs($this->treasurer->user);

    $this->postJson(route('app.loans.eligibility'), [
        'member_id' => $this->borrower->id,
        'principal_ngwee' => 1_000_000,
    ])
        ->assertOk()
        ->assertJson([
            'eligible' => true,
            'ceiling_ngwee' => 1_000_000,
            'cumulative_savings_ngwee' => 500_000,
            'tenor_months' => 4,
            'compressed' => false,
            'lockdown' => false,
            'has_open_loan' => false,
        ])
        ->assertJsonStructure(['eligible', 'reasons', 'months_available', 'earned_tenor_months']);
});

it('names every failed condition on the eligibility endpoint', function () {
    $this->actingAs($this->treasurer->user);

    $this->postJson(route('app.loans.eligibility'), [
        'member_id' => $this->borrower->id,
        'principal_ngwee' => 5_000_000,
    ])
        ->assertOk()
        ->assertJsonPath('eligible', false)
        ->assertJsonPath('reasons.0.code', 'exceeds_savings_multiple');
});

it('captures a request and sends the treasurer to the loan', function () {
    $this->actingAs($this->treasurer->user);

    $this->post(route('app.loans.store'), [
        'member_id' => $this->borrower->id,
        'principal_ngwee' => 1_000_000,
    ])->assertRedirect();

    expect(Loan::query()->where('member_id', $this->borrower->id)->first())
        ->status->toBe(LoanStatus::Requested)
        ->tenor_months->toBe(4);
});

it('returns the eligibility reasons as a form error when a request is refused', function () {
    $this->actingAs($this->treasurer->user);

    $this->from(route('app.loans.create'))
        ->post(route('app.loans.store'), [
            'member_id' => $this->borrower->id,
            'principal_ngwee' => 5_000_000,
        ])
        ->assertRedirect(route('app.loans.create'))
        ->assertSessionHasErrors('principal_ngwee');

    expect(Loan::query()->count())->toBe(0);
});

it('approves on a second committee member confirming with their own credentials', function () {
    $loan = ($this->requested)();
    $this->actingAs($this->chair->user);

    $this->post(route('app.loans.approve', $loan), [
        'approver_email' => $this->viceChair->user->email,
        'approver_password' => 'password',
    ])->assertRedirect();

    expect($loan->refresh())
        ->status->toBe(LoanStatus::Approved)
        ->approved_by_member_id->toBe($this->chair->id)
        ->second_approver_member_id->toBe($this->viceChair->id);
});

it('refuses an approval confirmed by the same user twice', function () {
    $loan = ($this->requested)();
    $this->actingAs($this->chair->user);

    $this->from(route('app.loans.show', $loan))
        ->post(route('app.loans.approve', $loan), [
            'approver_email' => $this->chair->user->email,
            'approver_password' => 'password',
        ])
        ->assertSessionHasErrors('approver_email');

    expect($loan->refresh()->status)->toBe(LoanStatus::Requested);
});

it('refuses an approval confirmed by someone without the permission', function () {
    $loan = ($this->requested)();
    $this->actingAs($this->chair->user);

    $this->from(route('app.loans.show', $loan))
        ->post(route('app.loans.approve', $loan), [
            'approver_email' => $this->treasurer->user->email,
            'approver_password' => 'password',
        ])
        ->assertSessionHasErrors('approver_email');

    expect($loan->refresh()->status)->toBe(LoanStatus::Requested);
});

it('refuses an approval confirmed with the wrong password', function () {
    $loan = ($this->requested)();
    $this->actingAs($this->chair->user);

    $this->from(route('app.loans.show', $loan))
        ->post(route('app.loans.approve', $loan), [
            'approver_email' => $this->viceChair->user->email,
            'approver_password' => 'not-the-password',
        ])
        ->assertSessionHasErrors('approver_password');
});

it('keeps approval away from the treasurer', function () {
    $loan = ($this->requested)();
    $this->actingAs($this->treasurer->user);

    $this->post(route('app.loans.approve', $loan), [
        'approver_email' => $this->chair->user->email,
        'approver_password' => 'password',
    ])->assertForbidden();
});

it('shows the queue and disburses from it, but only to the treasurer', function () {
    $loan = ($this->approved)();

    $this->actingAs($this->treasurer->user);

    $this->get(route('app.loans.queue'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('app/loans/Queue')
            ->has('queue', 1)
            ->where('queue.0.id', $loan->id)
            ->where('committed_ngwee', 1_000_000));

    $this->post(route('app.loans.disburse', $loan))->assertRedirect();

    expect($loan->refresh()->status)->toBe(LoanStatus::Disbursed);
});

it('keeps the disbursement queue away from the chairperson', function () {
    $this->actingAs($this->chair->user);

    $this->get(route('app.loans.queue'))->assertForbidden();
});

it('records a repayment for the treasurer', function () {
    $loan = app(LoanDisbursementQueue::class)->disburse(($this->approved)(), $this->january, $this->treasurer);

    $this->actingAs($this->treasurer->user);

    $this->post(route('app.loans.repayments.store', $loan), [
        'amount_ngwee' => 300_000,
        'received_on' => '2026-02-09',
    ])->assertRedirect();

    expect($loan->refresh()->current_balance_ngwee->getMinorAmount()->toInt())->toBe(700_000);
});

it('takes a default through to an enforced collateral claim', function () {
    $loan = app(LoanDisbursementQueue::class)->disburse(($this->approved)(), $this->january, $this->treasurer);

    $this->actingAs($this->chair->user);

    $this->post(route('app.loans.default', $loan), ['reason' => 'No contact since March.'])->assertRedirect();
    expect($loan->refresh()->status)->toBe(LoanStatus::Defaulted);

    $this->post(route('app.loans.collateral.store', $loan), [
        'items' => [
            ['description' => 'Deep freezer', 'estimated_value_ngwee' => 700_000],
            ['description' => 'Living room suite', 'estimated_value_ngwee' => 400_000],
        ],
    ])->assertRedirect();

    $claim = $loan->refresh()->collateralClaim;
    expect($claim->status)->toBe(CollateralClaimStatus::Draft);

    $this->post(route('app.collateral.sign-off', $claim), [
        'approver_email' => $this->viceChair->user->email,
        'approver_password' => 'password',
    ])->assertRedirect();

    $this->post(route('app.collateral.enforce', $claim))->assertRedirect();

    expect($claim->refresh()->status)->toBe(CollateralClaimStatus::Enforced);
});

it('renders the loan detail with its schedule, ledger and abilities', function () {
    $loan = app(LoanDisbursementQueue::class)->disburse(($this->approved)(), $this->january, $this->treasurer);

    $this->actingAs($this->treasurer->user);

    $this->get(route('app.loans.show', $loan))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('app/loans/Show')
            ->has('schedule', 4)
            ->has('ledger', 1)
            ->where('loan.abilities.recordRepayment', true)
            ->where('loan.abilities.approve', false));
});

it('renders the workbook matrix and the borrowing targets', function () {
    $this->actingAs($this->treasurer->user);

    $this->get(route('app.loans.matrix'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('app/loans/Matrix')->has('matrix.rows'));

    $this->get(route('app.loans.targets'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('app/loans/Targets')->where('target_ngwee', 5_000_000));
});

it('shows a member their own loan and lets them ask for one', function () {
    $this->actingAs($this->borrower->user);

    $this->get(route('my.loan'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('my/Loan')
            ->where('loan', null)
            ->where('rules.ceiling_ngwee', 1_000_000)
            ->where('eligibility.eligible', true));

    $this->post(route('my.loan.store'), [
        'member_id' => $this->borrower->id,
        'principal_ngwee' => 500_000,
    ])->assertRedirect();

    expect(Loan::query()->where('member_id', $this->borrower->id)->count())->toBe(1);
});

it('lists a member their settled and rejected loans as a flat history', function () {
    $rejected = app(LoanApplicationService::class)->reject(($this->requested)(), $this->chair, 'Asked for too much.');

    $this->actingAs($this->borrower->user);

    $this->get(route('my.loan'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('my/Loan')
            ->has('history', 1)
            ->where('history.0.id', $rejected->id));
});

it('will not let a member request a loan in somebody else than their own name', function () {
    $other = memberWithRole($this->cycle);
    $this->actingAs($this->borrower->user);

    $this->post(route('my.loan.store'), [
        'member_id' => $other->id,
        'principal_ngwee' => 500_000,
    ])->assertForbidden();
});

it('exports the loans workbook as a spreadsheet and as a printable sheet', function (string $format) {
    app(LoanDisbursementQueue::class)->disburse(($this->approved)(), $this->january, $this->treasurer);

    $this->actingAs($this->treasurer->user);

    $this->get(route('app.loans.export', $format))
        ->assertOk()
        ->assertDownload();
})->with(['xlsx', 'pdf']);

it('keeps the export behind the same permission as the register', function () {
    $this->actingAs($this->borrower->user);

    $this->get(route('app.loans.export', 'xlsx'))->assertForbidden();
});
