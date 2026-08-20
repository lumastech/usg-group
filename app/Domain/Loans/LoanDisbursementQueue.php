<?php

namespace App\Domain\Loans;

use App\Domain\Support\MoneyMutator;
use App\Enums\LoanStatus;
use App\Enums\LoanTransactionType;
use App\Exceptions\DomainRuleException;
use App\Exceptions\LoanNotEligibleException;
use App\Models\CycleMonth;
use App\Models\Loan;
use App\Models\Member;
use App\Support\Kwacha;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The trading-day queue: who gets paid, and in what order.
 *
 * Approved loans are disbursed first-come first-served by the moment the request was
 * captured. Money is finite on the day, so the order is the fairness — jumping it is
 * allowed, but only against a typed reason that stays on the record.
 */
class LoanDisbursementQueue
{
    public function __construct(
        protected LoanEligibilityService $eligibility,
        protected RepaymentScheduleGenerator $schedule,
        protected LoanLedger $ledger,
        protected MoneyMutator $mutator,
    ) {}

    /**
     * The approved loans still waiting, oldest request first.
     *
     * @return Collection<int, Loan>
     */
    public function pending(CycleMonth $month): Collection
    {
        return Loan::query()
            ->forCycle($month->cycle_id)
            ->where('status', LoanStatus::Approved->value)
            ->with('member')
            ->orderBy('requested_at')
            ->orderBy('id')
            ->get();
    }

    /** One-based position in the queue, or null when the loan is not in it. */
    public function positionOf(Loan $loan, CycleMonth $month): ?int
    {
        $index = $this->pending($month)->search(fn (Loan $queued): bool => $queued->is($loan));

        return $index === false ? null : $index + 1;
    }

    /** Whether disbursing this loan now would skip somebody who asked first. */
    public function wouldJumpQueue(Loan $loan, CycleMonth $month): bool
    {
        return ($this->positionOf($loan, $month) ?? 1) > 1;
    }

    /**
     * Pays out an approved loan and starts its schedule.
     *
     * Eligibility is checked again here, not just at request: a member's savings,
     * status or other loans can all have moved in the days since, and the fund must not
     * pay out against conditions that have since stopped being true.
     */
    public function disburse(
        Loan $loan,
        CycleMonth $month,
        Member $actor,
        ?string $outOfOrderReason = null,
    ): Loan {
        if ($loan->status !== LoanStatus::Approved) {
            throw DomainRuleException::make(
                'Only an approved loan can be disbursed; this one is '.strtolower($loan->status->label()).'.'
            );
        }

        $recheck = $this->eligibility->check(
            $loan->member,
            $loan->principal_ngwee,
            $loan->requested_at,
            $loan->discretion_override,
            ignoring: $loan,
        );

        if ($recheck->failed()) {
            throw LoanNotEligibleException::from($recheck);
        }

        $jumped = $this->wouldJumpQueue($loan, $month);
        $reason = $outOfOrderReason === null ? null : trim($outOfOrderReason);

        if ($jumped && ($reason === null || $reason === '')) {
            throw DomainRuleException::make(
                'Loans are disbursed in the order they were requested. Paying this one first needs a written reason.'
            );
        }

        return $this->mutator->mutate(
            $actor,
            'Disbursed '.Kwacha::format($loan->principal_ngwee)." to {$loan->member->full_name} (loan #{$loan->id})",
            function () use ($loan, $month, $actor, $jumped, $reason): Loan {
                $loan->forceFill([
                    'status' => LoanStatus::Disbursed,
                    'disbursed_at' => Carbon::now(),
                    'disbursed_by_member_id' => $actor->id,
                    'disbursement_cycle_month_id' => $month->id,
                    'disbursement_position' => $this->nextPosition($month),
                    'out_of_order_reason' => $jumped ? $reason : null,
                ])->save();

                $this->ledger->post(
                    $loan,
                    LoanTransactionType::Disbursement,
                    $loan->principal_ngwee,
                    $month->disbursement_on,
                    $month,
                    $actor,
                    notes: $jumped ? 'Disbursed out of order: '.$reason : null,
                );

                $this->schedule->generate($loan, $month);

                return $loan->refresh();
            },
            ['loan_id' => $loan->id, 'cycle_month_id' => $month->id],
        );
    }

    /** The next slot on the day's disbursement sheet. */
    protected function nextPosition(CycleMonth $month): int
    {
        return (int) Loan::query()
            ->forCycle($month->cycle_id)
            ->where('disbursement_cycle_month_id', $month->id)
            ->max('disbursement_position') + 1;
    }
}
