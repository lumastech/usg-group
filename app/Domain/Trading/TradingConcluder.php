<?php

namespace App\Domain\Trading;

use App\Domain\Loans\InterestEngine;
use App\Domain\Loans\LoanRepaymentService;
use App\Domain\Loans\PenaltyService;
use App\Domain\Loans\ScheduledRepayments;
use App\Domain\Savings\MemberBalanceCalculator;
use App\Domain\Savings\SavingsLedger;
use App\Enums\CycleMonthStatus;
use App\Enums\DeclarationStatus;
use App\Enums\LoanScheduleItemStatus;
use App\Enums\LoanStatus;
use App\Enums\TradingSessionStatus;
use App\Exceptions\DomainRuleException;
use App\Exceptions\TradingSessionClosedException;
use App\Models\CycleMonth;
use App\Models\Declaration;
use App\Models\Loan;
use App\Models\LoanScheduleItem;
use App\Models\Member;
use App\Models\TradingEntry;
use App\Models\TradingSession;
use App\Support\Kwacha;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Concluding the trading session: the one act that turns the day's sheet into money.
 *
 * Everything the treasurer marked at the table has, until this moment, been a
 * worksheet. Concluding posts all of it at once — savings deposits, loan repayments,
 * the month's interest and the penalties for what was missed — inside a single
 * transaction. Either the whole month lands or none of it does; a half-posted trading
 * day is the one outcome the group cannot reconcile by hand.
 */
class TradingConcluder
{
    public function __construct(
        protected SavingsLedger $savings,
        protected LoanRepaymentService $repayments,
        protected ScheduledRepayments $scheduled,
        protected InterestEngine $interest,
        protected PenaltyService $penalties,
        protected MemberBalanceCalculator $balances,
    ) {}

    /**
     * What concluding would post, without posting any of it.
     *
     * The treasurer confirms against these figures, so they are counted from the same
     * rows the conclusion will walk rather than estimated.
     *
     * @return array<string, mixed>
     */
    public function preview(TradingSession $session): array
    {
        $month = $session->cycleMonth;
        $entries = $this->postableEntries($session);
        $previous = $month->cycle->monthAt($month->sequence - 1);

        $deposits = $entries->filter(fn (TradingEntry $e): bool => $e->getRawOriginal('savings_portion_ngwee') > 0);
        $repayments = $entries->filter(fn (TradingEntry $e): bool => $e->getRawOriginal('repayment_portion_ngwee') > 0);

        return [
            'month_label' => $month->label(),
            'deposits' => [
                'count' => $deposits->count(),
                'total_ngwee' => (int) $deposits->sum(fn (TradingEntry $e): int => $e->getRawOriginal('savings_portion_ngwee')),
            ],
            'repayments' => [
                'count' => $repayments->count(),
                'total_ngwee' => (int) $repayments->sum(fn (TradingEntry $e): int => $e->getRawOriginal('repayment_portion_ngwee')),
            ],
            'interest' => [
                'count' => $this->loansAwaitingInterest($month)->count(),
            ],
            'missed_installments' => [
                'count' => $previous === null ? 0 : $this->shortfallItems($previous)->count(),
                'month_label' => $previous?->label(),
            ],
            'late_penalties' => [
                'count' => $entries->filter(fn (TradingEntry $e): bool => $e->penalty_days > 0)->count(),
                'days' => (int) $entries->sum(fn (TradingEntry $e): int => $e->penalty_days),
            ],
            'unreceived' => [
                'count' => $session->entries()
                    ->whereNull('received_at')
                    ->where('expected_in_ngwee', '>', 0)
                    ->count(),
            ],
        ];
    }

    /**
     * Posts the whole month.
     *
     * The order is the constitution's, not a convenience: last month is closed first so
     * a missed installment's 10% is in the balance before this month's interest is
     * worked out on it, and the interest is charged before repayments are allocated so
     * the money clears what is actually owed rather than eating into principal.
     */
    public function conclude(TradingSession $session, Member $actor): TradingSession
    {
        if (! $session->isOpen()) {
            throw new TradingSessionClosedException(
                'The '.$session->cycleMonth->label().' trading session has already been concluded.'
            );
        }

        $month = $session->cycleMonth;

        return DB::transaction(function () use ($session, $month, $actor): TradingSession {
            $previous = $month->cycle->monthAt($month->sequence - 1);

            if ($previous !== null) {
                $this->penalties->closeMonth($previous, $actor);
            }

            $this->interest->postForMonth($month);

            foreach ($this->postableEntries($session) as $entry) {
                $this->postEntry($entry, $month, $actor);
            }

            Declaration::query()
                ->forMonth($month)
                ->whereIn('status', [DeclarationStatus::Submitted->value, DeclarationStatus::Locked->value])
                ->update(['status' => DeclarationStatus::Processed->value]);

            $session->forceFill([
                'status' => TradingSessionStatus::Concluded,
                'concluded_by_member_id' => $actor->id,
                'concluded_at' => Carbon::now(),
            ])->save();

            $month->forceFill(['status' => CycleMonthStatus::Closed])->save();

            $this->balances->rebuildMonth($month);

            activity('trading')
                ->causedBy($actor->user)
                ->performedOn($session)
                ->withProperties(['actor_member_id' => $actor->id, 'cycle_month_id' => $month->id])
                ->event('trading.concluded')
                ->log("Concluded the {$month->label()} trading session");

            return $session->refresh();
        });
    }

    /**
     * One member's line: their savings, then their repayment.
     *
     * Both go through the services that own them, so the same refusals a treasurer
     * would meet entering them by hand apply here — and because the whole conclusion
     * is one transaction, a refusal on the twenty-ninth member unwinds the first
     * twenty-eight rather than leaving the month half posted.
     */
    protected function postEntry(TradingEntry $entry, CycleMonth $month, Member $actor): void
    {
        $member = $entry->member;
        $receivedOn = $entry->received_at ?? $month->disbursement_on;
        $savings = $entry->getRawOriginal('savings_portion_ngwee');
        $repayment = $entry->getRawOriginal('repayment_portion_ngwee');

        if ($savings > 0) {
            $this->savings->record(
                $member,
                $month,
                Kwacha::ofNgwee($savings),
                $actor,
                declared: $entry->declaration === null
                    ? null
                    : Kwacha::ofNgwee($entry->declaration->getRawOriginal('saving_amount_ngwee')),
                occurredOn: $receivedOn,
            );
        }

        if ($repayment <= 0) {
            return;
        }

        $loan = $this->scheduled->loanFor($member, $month);

        if ($loan === null) {
            throw new DomainRuleException(
                "{$member->full_name} paid ".Kwacha::format($repayment)
                    .' towards a loan, but has no loan outstanding to post it against.'
            );
        }

        $this->repayments->record($loan, Kwacha::ofNgwee($repayment), $actor, $receivedOn, $month);
    }

    /**
     * The rows that carry money to post: anything the treasurer marked as received.
     *
     * A member who never came to the table has nothing to post, and no deposit is
     * invented for them — the missing-declarations panel and the month's snapshot are
     * where that absence shows up.
     *
     * @return Collection<int, TradingEntry>
     */
    protected function postableEntries(TradingSession $session): Collection
    {
        return $session->entries()
            ->whereNotNull('received_at')
            ->with('member', 'declaration')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, Loan>
     */
    protected function loansAwaitingInterest(CycleMonth $month): Collection
    {
        return Loan::query()
            ->forCycle($month->cycle_id)
            ->whereIn('status', [LoanStatus::Disbursed->value, LoanStatus::Repaying->value])
            ->whereHas('scheduleItems', fn ($query) => $query->where('cycle_month_id', $month->id))
            ->get()
            ->reject(fn (Loan $loan): bool => $this->interest->alreadyCharged($loan, $month))
            ->values();
    }

    /**
     * @return Collection<int, LoanScheduleItem>
     */
    protected function shortfallItems(CycleMonth $month): Collection
    {
        return LoanScheduleItem::query()
            ->where('cycle_month_id', $month->id)
            ->whereIn('status', [
                LoanScheduleItemStatus::Pending->value,
                LoanScheduleItemStatus::PartiallyPaid->value,
            ])
            ->whereColumn('amount_paid_ngwee', '<', 'amount_due_ngwee')
            ->get();
    }
}
