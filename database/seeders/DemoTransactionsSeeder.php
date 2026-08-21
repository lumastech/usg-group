<?php

namespace Database\Seeders;

use App\Domain\Cycles\CurrentCycle;
use App\Domain\Declarations\DeclarationService;
use App\Domain\Loans\LoanApplicationService;
use App\Domain\Loans\LoanDisbursementQueue;
use App\Domain\Loans\ScheduledRepayments;
use App\Domain\Trading\TradingConcluder;
use App\Domain\Trading\TradingSessionService;
use App\Enums\MemberRole;
use App\Events\TradingSessionConcluded;
use App\Models\Cycle;
use App\Models\CycleMonth;
use App\Models\Member;
use App\Support\Kwacha;
use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Three months of realistic movement on the seeded cycle.
 *
 * Everything here goes through the domain services rather than inserting rows, so
 * what comes out is a database the real screens can be demonstrated against —
 * interest allocated by the pooled pro-rata rule, penalties charged where a payment
 * came in late, summaries rebuilt by the conclusion itself. Ledger rows written by
 * hand would look right on a matrix and be wrong everywhere a figure is derived.
 *
 * Run explicitly — `php artisan db:seed --class=DemoTransactionsSeeder` — and never
 * from DatabaseSeeder: it posts money, and a production deploy that ran it would
 * have to be unwound by hand.
 */
class DemoTransactionsSeeder extends Seeder
{
    /** How many of the cycle's months to play through. */
    public const MONTHS = 3;

    /** Members who take a loan, by member number. */
    public const BORROWER_NUMBERS = [3, 7, 12, 19];

    public function run(): void
    {
        $cycle = app(CurrentCycle::class)->get();

        if (! $cycle instanceof Cycle) {
            $this->command->error('No active cycle. Run UnityCycleSeeder first.');

            return;
        }

        /*
         * Concluding a month raises TradingSessionConcluded, whose listener renders a
         * PDF for every member and mails it. Neither belongs in a seed run, so the
         * event is faked and notifications are swallowed — the money still posts.
         */
        Event::fake([TradingSessionConcluded::class]);
        Notification::fake();

        $members = Member::query()->forCycle($cycle)->active()->orderBy('member_number')->get();
        $treasurer = $this->treasurer($members);

        if ($treasurer === null) {
            $this->command->error('No treasurer on the committee — assign one before seeding demo data.');

            return;
        }

        foreach ($cycle->months()->limit(self::MONTHS)->get() as $month) {
            $this->playMonth($cycle, $month, $members, $treasurer);
        }

        $this->stopPretendingTimeHasMoved();

        $this->command->info('Seeded '.self::MONTHS.' months of demo transactions.');
    }

    /**
     * @param  Collection<int, Member>  $members
     */
    protected function playMonth(Cycle $cycle, CycleMonth $month, $members, Member $treasurer): void
    {
        $this->pretendItIs($month->declarations_open_at->copy()->addHour());

        $declarations = app(DeclarationService::class);
        $repayments = app(ScheduledRepayments::class);

        foreach ($members as $index => $member) {
            $saving = $this->savingFor($cycle, $month, $index);

            /*
             * Two members a month simply do not declare. The declaration reminder, the
             * chase list on the console and the "missing" count on the sheet all have
             * nothing to show against a register where everybody always complies.
             */
            if ($index % 15 === 14) {
                continue;
            }

            try {
                $declarations->submit(
                    $member,
                    $month,
                    $saving,
                    $repayments->due($member, $month),
                    Kwacha::zero(),
                    $treasurer,
                    onBehalf: true,
                );
            } catch (Throwable $exception) {
                $this->command->warn("Declaration skipped for {$member->full_name}: {$exception->getMessage()}");
            }
        }

        $this->lendTo($month, $members, $treasurer);

        $this->pretendItIs($month->trading_concludes_on->copy()->setTime(10, 0));

        $sessions = app(TradingSessionService::class);
        $session = $sessions->openFor($month);
        $sessions->syncEntries($session);

        foreach ($session->entries()->with('declaration', 'member')->get() as $position => $entry) {
            $expected = $entry->getRawOriginal('expected_in_ngwee');

            if ($expected <= 0) {
                continue;
            }

            /*
             * Every other member with a loan installment pays two days after trading
             * day. The penalty is charged per day on the repayment, so it is targeted
             * at the entries that carry one — otherwise the demo data has no late
             * penalty in it at all, and neither the loan ledger nor the Social Fund
             * mirror has anything to show.
             */
            $late = $entry->getRawOriginal('repayment_portion_ngwee') > 0
                || ($entry->declaration?->getRawOriginal('loan_repayment_amount_ngwee') ?? 0) > 0
                    ? $position % 2 === 0
                    : false;

            $sessions->markReceived(
                $entry,
                Kwacha::ofNgwee($expected),
                $late
                    ? $month->trading_concludes_on->copy()->addDays(2)->setTime(9, 0)
                    : $month->trading_concludes_on->copy()->setTime(11, 0),
                $treasurer,
            );
        }

        try {
            app(TradingConcluder::class)->conclude($session, $treasurer);
        } catch (Throwable $exception) {
            $this->command->warn("Could not conclude {$month->label()}: {$exception->getMessage()}");
        }
    }

    /**
     * A handful of loans, requested and approved in the month before they are paid.
     *
     * Eligibility is genuinely checked, so a member who has not saved enough yet is
     * simply skipped rather than forced through — which is why nothing is disbursed
     * in the opening month.
     *
     * @param  Collection<int, Member>  $members
     */
    protected function lendTo(CycleMonth $month, $members, Member $treasurer): void
    {
        if ($month->sequence < 2) {
            return;
        }

        $borrowers = $members->whereIn('member_number', self::BORROWER_NUMBERS);
        $committee = $members->filter(fn (Member $m): bool => $m->isCommitteeMember())->values();

        if ($committee->count() < 2) {
            return;
        }

        $applications = app(LoanApplicationService::class);
        $queue = app(LoanDisbursementQueue::class);

        foreach ($borrowers as $borrower) {
            try {
                $loan = $applications->request($borrower, Kwacha::of(1_000), $treasurer);
                $loan = $applications->approve($loan, $committee[0], $committee[1]);

                $queue->disburse($loan, $month, $treasurer);
            } catch (Throwable $exception) {
                $this->command->warn("No loan for {$borrower->full_name}: {$exception->getMessage()}");
            }
        }
    }

    /**
     * A spread of savings rather than thirty identical K500 rows.
     *
     * The cap in a lockdown month is respected because the declaration service would
     * refuse anything above it — the figures here have to be ones the group could
     * really have declared.
     */
    protected function savingFor(Cycle $cycle, CycleMonth $month, int $index): Money
    {
        $steps = [1, 1, 2, 1, 3, 2, 1, 4, 2, 1];
        $increment = $cycle->getRawOriginal('savings_increment_ngwee');
        $ngwee = $cycle->getRawOriginal('min_savings_ngwee')
            + ($steps[$index % count($steps)] - 1) * $increment;

        $cap = $cycle->savingsCapForMonth($month->sequence);

        return Kwacha::ofNgwee($cap === null ? $ngwee : min($ngwee, $cap->getMinorAmount()->toInt()));
    }

    /** @param  Collection<int, Member>  $members */
    protected function treasurer($members): ?Member
    {
        return $members->first(fn (Member $member): bool => $member->hasRole(MemberRole::Treasurer))
            ?? $members->first(fn (Member $member): bool => $member->isCommitteeMember());
    }

    /**
     * Move the clock so the domain's own window rules accept the entry.
     *
     * Declarations only open on the 1st and trading only concludes on the adjusted
     * 7th. Rather than bypass those rules for the demo — which would seed data the
     * application itself would refuse — the seeder pretends to be standing on the day.
     */
    protected function pretendItIs(CarbonInterface $moment): void
    {
        Carbon::setTestNow($moment);
        CarbonImmutable::setTestNow($moment);
    }

    protected function stopPretendingTimeHasMoved(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
    }
}
