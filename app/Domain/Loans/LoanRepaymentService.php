<?php

namespace App\Domain\Loans;

use App\Domain\Support\MoneyMutator;
use App\Enums\LoanScheduleItemStatus;
use App\Enums\LoanStatus;
use App\Enums\LoanTransactionType;
use App\Exceptions\DomainRuleException;
use App\Models\CycleMonth;
use App\Models\Loan;
use App\Models\LoanTransaction;
use App\Models\Member;
use App\Support\Kwacha;
use Brick\Money\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Takes in a repayment and works out what it paid off.
 *
 * Money clears penalties first, then the interest already charged, then principal. That
 * order matters: interest is charged on the principal still outstanding, so letting a
 * short payment reduce principal ahead of a penalty would quietly cut the interest the
 * rest of the group is owed.
 */
class LoanRepaymentService
{
    public function __construct(
        protected LoanLedger $ledger,
        protected PenaltyService $penalties,
        protected MoneyMutator $mutator,
    ) {}

    public function record(
        Loan $loan,
        Money $amount,
        Member $actor,
        ?CarbonInterface $receivedOn = null,
        ?CycleMonth $month = null,
    ): LoanTransaction {
        $receivedOn ??= Carbon::today();
        $ngwee = Kwacha::toNgwee($amount);

        if ($ngwee <= 0) {
            throw DomainRuleException::make('A repayment must be more than nothing.');
        }

        if (! in_array($loan->status, [LoanStatus::Disbursed, LoanStatus::Repaying, LoanStatus::Defaulted], true)) {
            throw DomainRuleException::make(
                'Only a loan that has been disbursed can take a repayment; this one is '
                    .strtolower($loan->status->label()).'.'
            );
        }

        $month ??= $loan->cycle->monthFor($receivedOn);

        return $this->mutator->mutate(
            $actor,
            'Recorded a repayment of '.Kwacha::format($amount)." on loan #{$loan->id} for {$loan->member->full_name}",
            function () use ($loan, $amount, $ngwee, $actor, $receivedOn, $month): LoanTransaction {
                /*
                 * The late penalty is charged before the money is allocated, so a payment
                 * that arrives three days late clears its own K300 first rather than
                 * looking fully paid and attracting the charge afterwards.
                 */
                if ($month !== null) {
                    $daysLate = $this->penalties->daysLate($month, $receivedOn, $loan);
                    $this->penalties->chargeLatePayment($loan, $month, $daysLate, $receivedOn, $actor);
                }

                $portions = $this->allocate($loan, $ngwee);

                $transaction = $this->ledger->post(
                    $loan,
                    LoanTransactionType::Repayment,
                    $amount,
                    $receivedOn,
                    $month,
                    $actor,
                    $portions,
                );

                if ($month !== null) {
                    $this->creditInstallment($loan, $month, $ngwee);
                }

                $this->settleIfCleared($loan);

                return $transaction;
            },
            ['loan_id' => $loan->id, 'amount_ngwee' => $ngwee],
        );
    }

    /**
     * Splits a payment across penalties, interest and principal.
     *
     * @return array{principal: int, interest: int, penalty: int}
     */
    public function allocate(Loan $loan, int $ngwee): array
    {
        $penalty = min($ngwee, $this->ledger->outstandingPenaltiesNgwee($loan));
        $remaining = $ngwee - $penalty;

        $interest = min($remaining, $this->ledger->outstandingInterestNgwee($loan));
        $remaining -= $interest;

        /* Anything beyond the principal still owed is an overpayment and is not applied. */
        $principal = min($remaining, $loan->principalOutstandingNgwee());

        return ['principal' => $principal, 'interest' => $interest, 'penalty' => $penalty];
    }

    /** Credits the month's installment and marks it paid once it is fully covered. */
    protected function creditInstallment(Loan $loan, CycleMonth $month, int $ngwee): void
    {
        $item = $loan->scheduleItems()->where('cycle_month_id', $month->id)->first()
            ?? $loan->nextDueItem();

        if ($item === null) {
            return;
        }

        $paid = $item->getRawOriginal('amount_paid_ngwee') + $ngwee;
        $due = $item->getRawOriginal('amount_due_ngwee');

        $item->forceFill([
            'amount_paid_ngwee' => $paid,
            'paid_at' => $paid >= $due ? Carbon::now() : null,
            'status' => $paid >= $due
                ? LoanScheduleItemStatus::Paid
                : LoanScheduleItemStatus::PartiallyPaid,
        ])->save();
    }

    /** A loan whose ledger reads zero is finished. */
    protected function settleIfCleared(Loan $loan): void
    {
        if ($this->ledger->balanceNgwee($loan) > 0) {
            if ($loan->status === LoanStatus::Disbursed) {
                $loan->forceFill(['status' => LoanStatus::Repaying])->save();
            }

            return;
        }

        $loan->forceFill(['status' => LoanStatus::Settled, 'settled_at' => Carbon::now()])->save();
    }
}
