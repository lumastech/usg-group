<?php

namespace App\Domain\Notifications;

use App\Domain\Loans\LoanLedger;
use App\Domain\Loans\ScheduledRepayments;
use App\Enums\LoanStatus;
use App\Models\Cycle;
use App\Models\CycleMonth;
use App\Models\Declaration;
use App\Models\Loan;
use App\Models\Member;
use App\Notifications\DeclarationReminder;
use App\Notifications\DeclarationWindowOpened;
use App\Notifications\FinalDeadlineCountdown;
use App\Notifications\LoanLockdownNotice;
use App\Notifications\RepaymentDueSoon;
use App\Notifications\TradingDayScheduled;
use Carbon\CarbonInterface;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * Every notification the calendar owes the group, resolved for one day.
 *
 * The month's rhythm is already written down once, in the cycle_months rows that
 * CycleMonthPlanner laid out — including the weekend adjustment, which is why
 * nothing here recomputes a date. Each rule asks "does today match a date this
 * cycle already holds?" and sends if it does. That is what makes the whole thing
 * testable by travelling the clock, and what stops the notifications drifting away
 * from the dates the declaration and trading screens enforce.
 *
 * Every send is claimed in `notification_dispatches` before it goes out, so a
 * second run on the same day — a retry, a manual invocation, two servers — sends
 * nothing twice.
 */
class CycleNotificationScheduler
{
    /** How many days before trading day the repayment reminder goes out. */
    public const REPAYMENT_REMINDER_DAYS_BEFORE = 2;

    /** How many days before the lockdown month opens the first warning goes out. */
    public const LOCKDOWN_WARNING_DAYS_BEFORE = 7;

    /** The countdown opens this many months before the month the deadline falls in. */
    public const COUNTDOWN_STARTS_MONTHS_BEFORE = 1;

    public function __construct(
        protected ScheduledRepayments $scheduled,
        protected LoanLedger $ledger,
        protected NotificationDispatchLog $log,
    ) {}

    /**
     * Run every rule for one day.
     *
     * @return array<string, int> recipients notified, keyed by rule
     */
    public function run(Cycle $cycle, CarbonInterface $today): array
    {
        $today = $today->copy()->startOfDay();

        return array_filter([
            'declarations.open' => $this->declarationWindowOpened($cycle, $today),
            'declarations.reminder' => $this->declarationReminder($cycle, $today),
            'trading.day' => $this->tradingDay($cycle, $today),
            'repayments.due' => $this->repaymentDue($cycle, $today),
            'loans.lockdown' => $this->lockdown($cycle, $today),
            'loans.final-deadline' => $this->finalDeadline($cycle, $today),
        ], fn (?int $sent): bool => $sent !== null);
    }

    /** 08:00 on the 1st: the window is open, to every active member. */
    protected function declarationWindowOpened(Cycle $cycle, CarbonInterface $today): ?int
    {
        $month = $this->monthWhere($cycle, fn (CycleMonth $m): bool => $m->declarations_open_at->isSameDay($today));

        if ($month === null) {
            return null;
        }

        return $this->log->once($cycle, "declarations.open:{$month->id}", $today, fn (): int => $this->notify(
            $this->activeMembers($cycle),
            fn (): DeclarationWindowOpened => new DeclarationWindowOpened($month),
        ));
    }

    /** The 3rd: a nudge to the members who have not declared yet, and only to them. */
    protected function declarationReminder(Cycle $cycle, CarbonInterface $today): ?int
    {
        $month = $this->monthWhere($cycle, fn (CycleMonth $m): bool => $m->declarations_close_at->isSameDay($today));

        if ($month === null) {
            return null;
        }

        return $this->log->once($cycle, "declarations.reminder:{$month->id}", $today, function () use ($cycle, $month): int {
            $declared = Declaration::query()->forMonth($month)->pluck('member_id')->all();

            return $this->notify(
                $this->activeMembers($cycle)->reject(
                    fn (Member $member): bool => in_array($member->id, $declared, true),
                ),
                fn (): DeclarationReminder => new DeclarationReminder($month),
            );
        });
    }

    /** The adjusted 7th: the committee is told the sheet is ready to work. */
    protected function tradingDay(Cycle $cycle, CarbonInterface $today): ?int
    {
        $month = $this->monthWhere($cycle, fn (CycleMonth $m): bool => $m->trading_concludes_on->isSameDay($today));

        if ($month === null) {
            return null;
        }

        return $this->log->once($cycle, "trading.day:{$month->id}", $today, function () use ($cycle, $month): int {
            $declarations = Declaration::query()->forMonth($month)->get();

            $summary = [
                'declarations' => $declarations->count(),
                'members' => $this->activeMembers($cycle)->count(),
                'expected_in_ngwee' => (int) $declarations->sum(
                    fn (Declaration $declaration): int => $declaration->expectedInNgwee(),
                ),
            ];

            return $this->notify(
                $this->committeeMembers($cycle),
                fn (): TradingDayScheduled => new TradingDayScheduled($month, $summary),
            );
        });
    }

    /** Two days before trading, to the members with an installment falling due. */
    protected function repaymentDue(Cycle $cycle, CarbonInterface $today): ?int
    {
        $month = $this->monthWhere($cycle, fn (CycleMonth $m): bool => $m->trading_concludes_on
            ->copy()
            ->subDays(self::REPAYMENT_REMINDER_DAYS_BEFORE)
            ->isSameDay($today));

        if ($month === null) {
            return null;
        }

        return $this->log->once($cycle, "repayments.due:{$month->id}", $today, function () use ($cycle, $month): int {
            $sent = 0;

            foreach ($this->activeMembers($cycle) as $member) {
                $due = $this->scheduled->dueNgwee($member, $month);

                if ($due <= 0) {
                    continue;
                }

                $member->notify(new RepaymentDueSoon($month, $due));
                $sent++;
            }

            return $sent;
        });
    }

    /**
     * A week before the lockdown month opens, and again on its first day.
     *
     * The month is read from the cycle rather than hard-coded to September, so a
     * cycle that starts in a different month warns on its own dates.
     */
    protected function lockdown(Cycle $cycle, CarbonInterface $today): ?int
    {
        $month = $cycle->monthAt($cycle->loan_lockdown_starts_month);

        if ($month === null) {
            return null;
        }

        $opensOn = $month->month->copy()->startOfMonth();
        $warnOn = $opensOn->copy()->subDays(self::LOCKDOWN_WARNING_DAYS_BEFORE);

        $hasStarted = $opensOn->isSameDay($today);

        if (! $hasStarted && ! $warnOn->isSameDay($today)) {
            return null;
        }

        $key = 'loans.lockdown:'.$month->id.':'.($hasStarted ? 'started' : 'warning');

        return $this->log->once($cycle, $key, $today, fn (): int => $this->notify(
            $this->activeMembers($cycle),
            fn (): LoanLockdownNotice => new LoanLockdownNotice($cycle, $month, $hasStarted),
        ));
    }

    /**
     * Weekly through the run-in to the final repayment date, to anyone still owing.
     *
     * The first send is the first of the month before the deadline — 1 October for a
     * 7 November deadline — and every seventh day after it, so the cadence is the
     * same whichever day of the week that lands on.
     */
    protected function finalDeadline(Cycle $cycle, CarbonInterface $today): ?int
    {
        $deadline = $cycle->final_repayment_date->copy()->startOfDay();
        $opensOn = $deadline->copy()
            ->startOfMonth()
            ->subMonthsNoOverflow(self::COUNTDOWN_STARTS_MONTHS_BEFORE);

        if ($today->lessThan($opensOn) || $today->greaterThan($deadline)) {
            return null;
        }

        if ((int) $opensOn->diffInDays($today) % 7 !== 0) {
            return null;
        }

        return $this->log->once($cycle, 'loans.final-deadline:'.$today->toDateString(), $today, function () use ($cycle, $today, $deadline): int {
            $remaining = $this->remainingTradingDays($cycle, $today, $deadline);
            $sent = 0;

            foreach ($this->activeMembers($cycle) as $member) {
                $balance = $this->outstandingNgwee($member);

                if ($balance <= 0) {
                    continue;
                }

                $member->notify(new FinalDeadlineCountdown(
                    $cycle,
                    $balance,
                    $remaining,
                    $remaining === [] ? $balance : (int) ceil($balance / count($remaining)),
                    (int) $today->diffInDays($deadline),
                ));

                $sent++;
            }

            return $sent;
        });
    }

    /**
     * The trading days left between today and the deadline, inclusive.
     *
     * @return array<int, array{label: string, due_on: string}>
     */
    protected function remainingTradingDays(Cycle $cycle, CarbonInterface $today, CarbonInterface $deadline): array
    {
        return $cycle->months()
            ->get()
            ->filter(fn (CycleMonth $month): bool => $month->trading_concludes_on->betweenIncluded($today, $deadline))
            ->map(fn (CycleMonth $month): array => [
                'label' => $month->trading_concludes_on->format('j F'),
                'due_on' => $month->trading_concludes_on->toDateString(),
            ])
            ->values()
            ->all();
    }

    /** Everything a member still owes on their loans, penalties and interest included. */
    protected function outstandingNgwee(Member $member): int
    {
        return Loan::query()
            ->where('member_id', $member->id)
            ->whereIn('status', array_column(LoanStatus::outstanding(), 'value'))
            ->get()
            ->sum(fn (Loan $loan): int => $this->ledger->balanceNgwee($loan));
    }

    /**
     * @param  Collection<int, Member>  $members
     * @param  callable(Member): Notification  $notification
     */
    protected function notify(Collection $members, callable $notification): int
    {
        foreach ($members as $member) {
            $member->notify($notification($member));
        }

        return $members->count();
    }

    /** @return Collection<int, Member> */
    protected function activeMembers(Cycle $cycle): Collection
    {
        return Member::query()
            ->forCycle($cycle)
            ->active()
            ->with('user')
            ->orderBy('member_number')
            ->get();
    }

    /** @return Collection<int, Member> */
    protected function committeeMembers(Cycle $cycle): Collection
    {
        return $this->activeMembers($cycle)
            ->filter(fn (Member $member): bool => $member->isCommitteeMember())
            ->values();
    }

    /** @param  callable(CycleMonth): bool  $matches */
    protected function monthWhere(Cycle $cycle, callable $matches): ?CycleMonth
    {
        return $cycle->months()->get()->first($matches);
    }
}
