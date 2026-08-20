<?php

namespace App\Domain\Loans;

use App\Enums\LoanScheduleItemStatus;
use App\Enums\LoanStatus;
use App\Models\CycleMonth;
use App\Models\Loan;
use App\Models\LoanScheduleItem;
use App\Models\Member;
use App\Support\Kwacha;
use Brick\Money\Money;
use Illuminate\Support\Collection;

/**
 * What a member is due to repay in a given month.
 *
 * The declaration form prefills the repayment field from this, so a member is asked
 * to confirm the figure the schedule already holds them to rather than to remember it.
 * Nothing here writes — it reads the schedule the disbursement wrote.
 */
class ScheduledRepayments
{
    /** The installment still outstanding for one member in one month, in ngwee. */
    public function dueNgwee(Member $member, CycleMonth $month): int
    {
        return $this->itemsFor($member, $month)
            ->sum(fn (LoanScheduleItem $item): int => $item->outstandingNgwee());
    }

    public function due(Member $member, CycleMonth $month): Money
    {
        return Kwacha::ofNgwee($this->dueNgwee($member, $month));
    }

    /**
     * The month's installments across every loan the member is still repaying.
     *
     * A member holds one loan at a time, but a discretion override can leave two
     * running at once, and both fall due on the same trading day.
     *
     * @return Collection<int, LoanScheduleItem>
     */
    public function itemsFor(Member $member, CycleMonth $month): Collection
    {
        return LoanScheduleItem::query()
            ->where('cycle_month_id', $month->id)
            ->whereIn('status', [
                LoanScheduleItemStatus::Pending->value,
                LoanScheduleItemStatus::PartiallyPaid->value,
            ])
            ->whereHas('loan', fn ($query) => $query
                ->where('member_id', $member->id)
                ->whereIn('status', array_column(LoanStatus::outstanding(), 'value')))
            ->with('loan')
            ->orderBy('id')
            ->get();
    }

    /**
     * The loan a repayment declared for this month should be posted against.
     *
     * Oldest installment first, which is also the loan that has been running longest.
     */
    public function loanFor(Member $member, CycleMonth $month): ?Loan
    {
        $item = $this->itemsFor($member, $month)->first();

        if ($item !== null) {
            return $item->loan;
        }

        /*
         * No installment falls in this month — the loan may have been disbursed after
         * the schedule was drawn, or every item may already be settled — so the
         * repayment is posted against the loan the member is still carrying.
         */
        return Loan::query()
            ->where('member_id', $member->id)
            ->whereIn('status', array_column(LoanStatus::outstanding(), 'value'))
            ->orderBy('id')
            ->first();
    }
}
