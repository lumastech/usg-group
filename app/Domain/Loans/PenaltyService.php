<?php

namespace App\Domain\Loans;

use App\Enums\LoanScheduleItemStatus;
use App\Enums\LoanStatus;
use App\Enums\LoanTransactionType;
use App\Events\LatePenaltyCharged;
use App\Events\MissedInstallmentPenaltyCharged;
use App\Models\CycleMonth;
use App\Models\Loan;
use App\Models\LoanScheduleItem;
use App\Models\LoanTransaction;
use App\Models\Member;
use App\Support\Kwacha;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The two penalties the constitution attaches to a loan.
 *
 * A payment received after the month's trading date costs K100 for every day it is
 * late. A month that closes with the installment missed or only partly paid costs a
 * further 10% of the balance still outstanding. Both are charges on the ledger, not
 * adjustments to the schedule, so the member can see exactly what they were charged
 * and why.
 */
class PenaltyService
{
    public function __construct(protected LoanLedger $ledger) {}

    /** How many days after the trading date a payment arrived. Never negative. */
    public function daysLate(CycleMonth $month, CarbonInterface $receivedOn, ?Loan $loan = null): int
    {
        $due = $loan === null
            ? $month->disbursement_on
            : app(RepaymentScheduleGenerator::class)->dueDateFor($loan->cycle, $month);

        return max(0, (int) $due->startOfDay()->diffInDays($receivedOn->copy()->startOfDay(), false));
    }

    /**
     * Charges K100 a day for a late payment and tells the rest of the system.
     *
     * The Social Fund mirrors the same penalty, so this raises LatePenaltyCharged
     * rather than reaching into another module's ledger.
     */
    public function chargeLatePayment(
        Loan $loan,
        CycleMonth $month,
        int $daysLate,
        CarbonInterface $receivedOn,
        ?Member $actor = null,
    ): ?LoanTransaction {
        if ($daysLate < 1) {
            return null;
        }

        $perDay = $loan->cycle->late_transfer_penalty_per_day_ngwee;
        $amount = Kwacha::ofNgwee(Kwacha::toNgwee($perDay) * $daysLate);

        $transaction = $this->ledger->post(
            $loan,
            LoanTransactionType::LatePenaltyDaily,
            $amount,
            $receivedOn,
            $month,
            $actor,
            notes: $daysLate.' day'.($daysLate === 1 ? '' : 's').' late at '.Kwacha::format($perDay).' a day',
        );

        LatePenaltyCharged::dispatch($loan, $transaction, $daysLate);

        return $transaction;
    }

    /**
     * Closes a month and charges every installment that fell short.
     *
     * @return Collection<int, LoanTransaction>
     */
    public function closeMonth(CycleMonth $month, ?Member $actor = null): Collection
    {
        return LoanScheduleItem::query()
            ->where('cycle_month_id', $month->id)
            ->whereIn('status', [LoanScheduleItemStatus::Pending->value, LoanScheduleItemStatus::PartiallyPaid->value])
            ->with('loan.member', 'loan.cycle')
            ->get()
            ->reject(fn (LoanScheduleItem $item): bool => $item->loan->status === LoanStatus::Settled)
            ->map(fn (LoanScheduleItem $item): ?LoanTransaction => $this->closeInstallment($item, $month, $actor))
            ->filter()
            ->values();
    }

    /**
     * Settles one installment's fate and charges the 10% if it fell short.
     *
     * The penalty is on the whole outstanding balance, not on the shortfall — the
     * constitution treats a missed month as a breach of the loan, not a small debt.
     */
    public function closeInstallment(
        LoanScheduleItem $item,
        CycleMonth $month,
        ?Member $actor = null,
    ): ?LoanTransaction {
        $paid = $item->getRawOriginal('amount_paid_ngwee');
        $due = $item->getRawOriginal('amount_due_ngwee');

        if ($paid >= $due) {
            $item->forceFill(['status' => LoanScheduleItemStatus::Paid])->save();

            return null;
        }

        $item->forceFill([
            'status' => $paid > 0
                ? LoanScheduleItemStatus::PartiallyPaid
                : LoanScheduleItemStatus::Missed,
        ])->save();

        $loan = $item->loan;
        $outstanding = $this->ledger->balanceNgwee($loan);
        $penalty = (int) round($outstanding * $loan->cycle->missed_installment_penalty_bps / 10_000);

        if ($penalty <= 0) {
            return null;
        }

        $transaction = $this->ledger->post(
            $loan,
            LoanTransactionType::MissedInstallmentPenalty,
            Kwacha::ofNgwee($penalty),
            $month->disbursement_on,
            $month,
            $actor,
            notes: ($paid > 0 ? 'Partially paid' : 'Missed').' installment for '.$month->label()
                .': 10% of '.Kwacha::format($outstanding).' outstanding',
        );

        /*
         * Its own event, not LatePenaltyCharged: this penalty stays with the lending
         * pool while the daily one is mirrored into the Social Fund, and the fund's
         * reconciliation depends on the two never being confused for each other.
         */
        MissedInstallmentPenaltyCharged::dispatch($loan, $transaction, $month);

        return $transaction;
    }
}
