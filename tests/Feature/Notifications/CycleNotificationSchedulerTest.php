<?php

use App\Domain\Cycles\CurrentCycle;
use App\Domain\Cycles\CycleMonthPlanner;
use App\Domain\Declarations\DeclarationService;
use App\Domain\Notifications\CycleNotificationScheduler;
use App\Enums\LoanScheduleItemStatus;
use App\Enums\LoanStatus;
use App\Enums\LoanTransactionType;
use App\Enums\MemberRole;
use App\Models\Cycle;
use App\Models\Loan;
use App\Models\LoanScheduleItem;
use App\Models\LoanTransaction;
use App\Models\Member;
use App\Models\NotificationDispatch;
use App\Notifications\DeclarationReminder;
use App\Notifications\DeclarationWindowOpened;
use App\Notifications\FinalDeadlineCountdown;
use App\Notifications\LoanLockdownNotice;
use App\Notifications\RepaymentDueSoon;
use App\Notifications\TradingDayScheduled;
use App\Support\Kwacha;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    $this->seed(RoleSeeder::class);

    $this->cycle = Cycle::factory()->create();
    $this->months = app(CycleMonthPlanner::class)->plan($this->cycle);
    app(CurrentCycle::class)->set($this->cycle);

    $this->january = $this->months->firstWhere('sequence', 2);

    $this->treasurer = memberWithRole($this->cycle, MemberRole::Treasurer, ['member_number' => 1]);
    $this->alice = memberWithRole($this->cycle, MemberRole::Member, ['member_number' => 2]);
    $this->bene = memberWithRole($this->cycle, MemberRole::Member, ['member_number' => 3]);

    $this->scheduler = app(CycleNotificationScheduler::class);
});

it('tells every active member the window has opened, on the 1st', function () {
    $sent = $this->scheduler->run($this->cycle, Carbon::parse('2026-01-01'));

    expect($sent)->toHaveKey('declarations.open')
        ->and($sent['declarations.open'])->toBe(3);

    Notification::assertSentTo($this->alice, DeclarationWindowOpened::class);
    Notification::assertSentTo($this->treasurer, DeclarationWindowOpened::class);
});

it('sends nothing on a day the calendar has no rule for', function () {
    expect($this->scheduler->run($this->cycle, Carbon::parse('2026-01-15')))->toBe([]);

    Notification::assertNothingSent();
});

it('chases only the members who have not declared, on the 3rd', function () {
    $this->travelTo(Carbon::parse('2026-01-02 10:00'));

    app(DeclarationService::class)->submit(
        $this->alice,
        $this->january,
        Kwacha::of(500),
        Kwacha::zero(),
        Kwacha::zero(),
        actor: $this->alice,
    );

    $sent = $this->scheduler->run($this->cycle, Carbon::parse('2026-01-03'));

    expect($sent['declarations.reminder'])->toBe(2);

    Notification::assertNotSentTo($this->alice, DeclarationReminder::class);
    Notification::assertSentTo($this->bene, DeclarationReminder::class);
});

it('tells the committee, and only the committee, that it is trading day', function () {
    $sent = $this->scheduler->run($this->cycle, Carbon::parse('2026-01-07'));

    expect($sent['trading.day'])->toBe(1);

    Notification::assertSentTo($this->treasurer, TradingDayScheduled::class);
    Notification::assertNotSentTo($this->alice, TradingDayScheduled::class);
});

it('moves the trading-day notice when the 7th falls on a weekend', function () {
    // 7 February 2026 is a Saturday; the cycle's policy moves trading to the Monday.
    $february = $this->months->firstWhere('sequence', 3);

    expect($february->trading_concludes_on->toDateString())->toBe('2026-02-09');
    expect($this->scheduler->run($this->cycle, Carbon::parse('2026-02-07')))->not->toHaveKey('trading.day');

    $sent = $this->scheduler->run($this->cycle, Carbon::parse('2026-02-09'));

    expect($sent['trading.day'])->toBe(1);
});

it('warns members with an installment due, two days before trading, with the amount', function () {
    $loan = Loan::factory()->for($this->cycle)->for($this->alice)->create([
        'status' => LoanStatus::Repaying,
    ]);

    LoanScheduleItem::factory()->for($loan)->create([
        'cycle_month_id' => $this->january->id,
        'amount_due_ngwee' => 120_000,
        'amount_paid_ngwee' => 0,
        'status' => LoanScheduleItemStatus::Pending,
    ]);

    $sent = $this->scheduler->run($this->cycle, Carbon::parse('2026-01-05'));

    expect($sent['repayments.due'])->toBe(1);

    Notification::assertSentTo(
        $this->alice,
        RepaymentDueSoon::class,
        fn (RepaymentDueSoon $notification): bool => $notification->amountDueNgwee === 120_000,
    );
    Notification::assertNotSentTo($this->bene, RepaymentDueSoon::class);
});

it('warns about the lockdown a week out and again on the day it starts', function () {
    $warned = $this->scheduler->run($this->cycle, Carbon::parse('2026-08-25'));

    expect($warned['loans.lockdown'])->toBe(3);
    Notification::assertSentTo(
        $this->alice,
        LoanLockdownNotice::class,
        fn (LoanLockdownNotice $notice): bool => $notice->hasStarted === false,
    );

    $started = $this->scheduler->run($this->cycle, Carbon::parse('2026-09-01'));

    expect($started['loans.lockdown'])->toBe(3);
    Notification::assertSentTo(
        $this->alice,
        LoanLockdownNotice::class,
        fn (LoanLockdownNotice $notice): bool => $notice->hasStarted === true,
    );
});

it('counts down weekly from 1 October to members who still owe', function () {
    $loan = Loan::factory()->for($this->cycle)->for($this->alice)->create([
        'status' => LoanStatus::Repaying,
    ]);

    LoanTransaction::factory()->for($loan)->create([
        'type' => LoanTransactionType::Disbursement,
        'amount_ngwee' => 400_000,
        'balance_after_ngwee' => 400_000,
        'occurred_on' => Carbon::parse('2026-06-07'),
    ]);

    $first = $this->scheduler->run($this->cycle, Carbon::parse('2026-10-01'));

    expect($first['loans.final-deadline'])->toBe(1);
    Notification::assertSentTo(
        $this->alice,
        FinalDeadlineCountdown::class,
        fn (FinalDeadlineCountdown $countdown): bool => $countdown->balanceNgwee === 400_000
            && $countdown->daysRemaining === 37,
    );
    Notification::assertNotSentTo($this->bene, FinalDeadlineCountdown::class);

    expect($this->scheduler->run($this->cycle, Carbon::parse('2026-10-05')))
        ->not->toHaveKey('loans.final-deadline');
    expect($this->scheduler->run($this->cycle, Carbon::parse('2026-10-08')))
        ->toHaveKey('loans.final-deadline');
});

it('sends nothing before the countdown window opens', function () {
    expect($this->scheduler->run($this->cycle, Carbon::parse('2026-09-24')))
        ->not->toHaveKey('loans.final-deadline');
});

it('sends each batch once, however often the run is repeated', function () {
    $first = $this->scheduler->run($this->cycle, Carbon::parse('2026-01-01'));
    $second = $this->scheduler->run($this->cycle, Carbon::parse('2026-01-01'));

    expect($first['declarations.open'])->toBe(3)
        ->and($second)->toBe([])
        ->and(NotificationDispatch::query()->where('key', 'declarations.open:'.$this->january->id)->count())->toBe(1);

    Notification::assertSentToTimes($this->alice, DeclarationWindowOpened::class, 1);
});

it('records how many members each batch reached', function () {
    $this->scheduler->run($this->cycle, Carbon::parse('2026-01-01'));

    $dispatch = NotificationDispatch::query()->where('key', 'declarations.open:'.$this->january->id)->sole();

    expect($dispatch->recipients)->toBe(3)
        ->and($dispatch->cycle_id)->toBe($this->cycle->id)
        ->and($dispatch->sent_on->toDateString())->toBe('2026-01-01');
});

it('leaves a member who has left the group out of every batch', function () {
    Member::factory()->for($this->cycle)->leftEarly()->create(['member_number' => 9]);

    $sent = $this->scheduler->run($this->cycle, Carbon::parse('2026-01-01'));

    expect($sent['declarations.open'])->toBe(3);
});
