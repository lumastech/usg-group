<?php

use App\Domain\Cycles\CycleMonthPlanner;
use App\Domain\Declarations\DeclarationService;
use App\Domain\Loans\LoanApplicationService;
use App\Domain\Loans\LoanDisbursementQueue;
use App\Domain\Savings\SavingsLedger;
use App\Domain\Trading\TradingConcluder;
use App\Domain\Trading\TradingSessionService;
use App\Enums\DeclarationStatus;
use App\Enums\LoanTransactionType;
use App\Enums\MemberRole;
use App\Enums\TradingSessionStatus;
use App\Exceptions\DomainRuleException;
use App\Exceptions\TradingSessionClosedException;
use App\Models\Cycle;
use App\Models\Declaration;
use App\Models\Member;
use App\Models\SavingsTransaction;
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

    $this->sessions = app(TradingSessionService::class);
    $this->concluder = app(TradingConcluder::class);
    $this->declarations = app(DeclarationService::class);

    $this->treasurer = memberWithRole($this->cycle, MemberRole::Treasurer);
    $this->chair = memberWithRole($this->cycle, MemberRole::Chairperson);

    $this->declare = fn (Member $member, $month, int $saving, int $repayment = 0, int $requested = 0, string $at = '2026-01-02 10:00') => $this->declarations->submit(
        $member,
        $month,
        Kwacha::of($saving),
        Kwacha::of($repayment),
        Kwacha::of($requested),
        actor: $member,
        at: Carbon::parse($at),
    );

    /** A member holding a disbursed loan, repaying from February onwards. */
    $this->borrower = function (int $kwacha = 4_000): Member {
        $borrower = Member::factory()->for($this->cycle)->create();
        app(SavingsLedger::class)->record($borrower, $this->december, Kwacha::of(5_000), $this->treasurer);

        $applications = app(LoanApplicationService::class);
        $loan = $applications->request($borrower, Kwacha::of($kwacha), $this->treasurer, Carbon::parse('2026-01-02 09:00'));
        $applications->approve($loan, $this->chair, $this->treasurer);
        app(LoanDisbursementQueue::class)->disburse($loan->refresh(), $this->january, $this->treasurer);

        return $borrower;
    };
});

it('previews exactly what concluding would post', function () {
    $saver = Member::factory()->for($this->cycle)->create();
    ($this->declare)($saver, $this->january, 1_000);

    $session = $this->sessions->openFor($this->january);
    $this->sessions->markReceived(
        $session->entries()->where('member_id', $saver->id)->first(),
        Kwacha::of(1_000),
        Carbon::parse('2026-01-09 10:00'),
        $this->treasurer,
    );

    $preview = $this->concluder->preview($session);

    expect($preview['deposits']['count'])->toBe(1)
        ->and($preview['deposits']['total_ngwee'])->toBe(100_000)
        ->and($preview['repayments']['count'])->toBe(0)
        ->and($preview['late_penalties']['count'])->toBe(1)
        ->and($preview['late_penalties']['days'])->toBe(2)
        ->and($preview['month_label'])->toBe('January 2026');
});

it('posts the month\'s savings deposits when the session is concluded', function () {
    $saver = Member::factory()->for($this->cycle)->create();
    ($this->declare)($saver, $this->january, 1_000);

    $session = $this->sessions->openFor($this->january);
    $this->sessions->markReceived(
        $session->entries()->first(),
        Kwacha::of(1_000),
        Carbon::parse('2026-01-07 10:00'),
        $this->treasurer,
    );

    $this->concluder->conclude($session, $this->treasurer);

    expect(Kwacha::toNgwee(app(SavingsLedger::class)->savedInMonth($saver, $this->january)))->toBe(100_000)
        ->and($session->refresh()->status)->toBe(TradingSessionStatus::Concluded)
        ->and($session->concluded_by_member_id)->toBe($this->treasurer->id)
        ->and(Declaration::query()->forMonth($this->january)->first()->status)
        ->toBe(DeclarationStatus::Processed);
});

it('posts a repayment against the borrower\'s loan and charges the month\'s interest', function () {
    $borrower = ($this->borrower)();
    $installment = $borrower->loans()->first()->scheduleItems()->where('cycle_month_id', $this->february->id)->first();

    ($this->declare)($borrower, $this->february, 500, 1_500, at: '2026-02-02 10:00');

    $session = $this->sessions->openFor($this->february);
    $entry = $session->entries()->where('member_id', $borrower->id)->first();
    $this->sessions->markReceived($entry, Kwacha::of(2_000), Carbon::parse('2026-02-09 10:00'), $this->treasurer);

    $this->concluder->conclude($session, $this->treasurer);

    $loan = $borrower->loans()->first()->refresh();

    expect($loan->transactions()->where('type', LoanTransactionType::InterestCharge->value)->count())->toBe(1)
        ->and($loan->transactions()->where('type', LoanTransactionType::Repayment->value)->count())->toBe(1)
        ->and(Kwacha::toNgwee(app(SavingsLedger::class)->savedInMonth($borrower, $this->february)))->toBe(50_000)
        ->and($installment->refresh()->getRawOriginal('amount_paid_ngwee'))->toBe(150_000);
});

it('rolls the whole month back when one member\'s line cannot be posted', function () {
    $saver = Member::factory()->for($this->cycle)->create();
    $ghost = Member::factory()->for($this->cycle)->create();

    ($this->declare)($saver, $this->january, 1_000);
    ($this->declare)($ghost, $this->january, 500);

    $session = $this->sessions->openFor($this->january);

    $this->sessions->markReceived(
        $session->entries()->where('member_id', $saver->id)->first(),
        Kwacha::of(1_000),
        Carbon::parse('2026-01-07 10:00'),
        $this->treasurer,
    );

    /*
     * The ghost is marked as having paid K500 towards a loan they do not hold. The
     * conclusion must refuse the whole month rather than banking the saver's deposit
     * and leaving the group to reconcile a half-posted trading day.
     */
    $ghostEntry = $session->entries()->where('member_id', $ghost->id)->first();
    $this->sessions->markReceived($ghostEntry, Kwacha::of(500), Carbon::parse('2026-01-07 10:00'), $this->treasurer);
    $ghostEntry->forceFill(['savings_portion_ngwee' => 0, 'repayment_portion_ngwee' => 50_000])->save();

    expect(fn () => $this->concluder->conclude($session, $this->treasurer))
        ->toThrow(DomainRuleException::class, 'has no loan outstanding to post it against');

    expect(SavingsTransaction::query()->count())->toBe(0)
        ->and($session->refresh()->status)->toBe(TradingSessionStatus::Open)
        ->and(Declaration::query()->forMonth($this->january)->get()
            ->every(fn (Declaration $row): bool => $row->status === DeclarationStatus::Locked))->toBeTrue();
});

it('charges the ten per cent penalty on last month\'s missed installment before this month\'s interest', function () {
    $borrower = ($this->borrower)();
    $march = $this->months->firstWhere('sequence', 4);

    /* February is declared but nothing is received, so its installment is missed. */
    ($this->declare)($borrower, $this->february, 500, 1_500, at: '2026-02-02 10:00');
    $february = $this->sessions->openFor($this->february);
    $this->concluder->conclude($february, $this->treasurer);

    ($this->declare)($borrower, $march, 500, 1_500, at: '2026-03-02 10:00');
    $marchSession = $this->sessions->openFor($march);

    $preview = $this->concluder->preview($marchSession);
    expect($preview['missed_installments']['count'])->toBe(1)
        ->and($preview['missed_installments']['month_label'])->toBe('February 2026');

    $this->concluder->conclude($marchSession, $this->treasurer);

    $loan = $borrower->loans()->first();

    expect($loan->transactions()
        ->where('type', LoanTransactionType::MissedInstallmentPenalty->value)
        ->count())->toBe(1);
});

it('refuses to conclude a session twice', function () {
    $saver = Member::factory()->for($this->cycle)->create();
    ($this->declare)($saver, $this->january, 500);

    $session = $this->sessions->openFor($this->january);
    $this->concluder->conclude($session, $this->treasurer);

    $this->concluder->conclude($session->refresh(), $this->treasurer);
})->throws(TradingSessionClosedException::class, 'has already been concluded');

it('posts nothing for a member who never came to the table', function () {
    $absent = Member::factory()->for($this->cycle)->create();
    ($this->declare)($absent, $this->january, 1_000);

    $session = $this->sessions->openFor($this->january);
    $this->concluder->conclude($session, $this->treasurer);

    expect(SavingsTransaction::query()->where('member_id', $absent->id)->count())->toBe(0);
});
