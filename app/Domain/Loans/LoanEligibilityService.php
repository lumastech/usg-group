<?php

namespace App\Domain\Loans;

use App\Domain\Savings\SavingsLedger;
use App\Enums\MemberStatus;
use App\Exceptions\InvalidLoanAmountException;
use App\Models\Loan;
use App\Models\Member;
use App\Support\Kwacha;
use Brick\Money\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Decides whether a member may borrow, and on what terms.
 *
 * Run twice for every loan: once when the request is captured, and again at the
 * disbursement window, because a member's savings, status or open loans can all have
 * moved in the days between. Nothing here writes — it only answers.
 */
class LoanEligibilityService
{
    public function __construct(
        protected SavingsLedger $savings,
        protected RepaymentScheduleGenerator $schedule,
    ) {}

    public function check(
        Member $member,
        Money $principal,
        ?CarbonInterface $on = null,
        bool $overriding = false,
        ?Loan $ignoring = null,
    ): LoanEligibility {
        $on ??= Carbon::today();
        $cycle = $member->cycle;
        $month = $cycle->monthFor($on);
        $principalNgwee = Kwacha::toNgwee($principal);

        $reasons = [];

        if ($member->status !== MemberStatus::Active) {
            $reasons[] = [
                'code' => 'member_not_active',
                'message' => "{$member->full_name} is {$member->status->label()} and may not borrow from this cycle.",
            ];
        }

        $openLoan = $member->loans()
            ->blocking()
            ->when($ignoring !== null, fn ($query) => $query->whereKeyNot($ignoring->getKey()))
            ->first();

        if ($openLoan !== null && ! $overriding) {
            $reasons[] = [
                'code' => 'existing_loan',
                'message' => 'Members may hold one loan at a time, and this one is '
                    .strtolower($openLoan->status->label()).'. A committee member may record a discretion override with a written reason.',
            ];
        }

        $cumulativeSavings = $month === null
            ? Kwacha::zero()
            : $this->savings->cumulativeSavings($member, $month);

        $ceilingNgwee = Kwacha::toNgwee($cumulativeSavings) * $cycle->max_loan_multiple;

        if ($principalNgwee > $ceilingNgwee) {
            $reasons[] = [
                'code' => 'exceeds_savings_multiple',
                'message' => 'A member may borrow up to '.$cycle->max_loan_multiple.' times their savings. '
                    .$member->full_name.' has saved '.Kwacha::format($cumulativeSavings)
                    .', so the ceiling is '.Kwacha::format($ceilingNgwee).'.',
            ];
        }

        /*
         * The September lockdown is absolute. Unlike the one-loan rule there is no
         * discretion override for it — no new money leaves the fund once the cycle has
         * turned towards its share-out.
         */
        $lockdown = $month !== null && $cycle->isLockdownMonth($month->sequence);

        if ($lockdown) {
            $reasons[] = [
                'code' => 'lockdown',
                'message' => 'No new loans are issued from '.$this->lockdownMonthLabel($member)
                    .' to the end of the cycle. This cannot be overridden.',
            ];
        }

        $earned = null;
        $tenor = null;
        $monthsAvailable = $month === null ? 0 : $this->schedule->monthsAvailableFrom($cycle, $month);

        try {
            $earned = LoanTenor::forNgwee($principalNgwee);
            $tenor = $earned->compressedTo(max(1, $monthsAvailable));
        } catch (InvalidLoanAmountException $exception) {
            $reasons[] = ['code' => 'invalid_amount', 'message' => $exception->getMessage()];
        }

        if ($earned !== null && $monthsAvailable < 1) {
            $reasons[] = [
                'code' => 'deadline_passed',
                'message' => 'Every loan must be repaid in full by '
                    .$cycle->final_repayment_date->format('j F Y').', and there is no repayment month left before it.',
            ];
        }

        return LoanEligibility::make(
            $reasons,
            $principalNgwee,
            Kwacha::toNgwee($cumulativeSavings),
            $ceilingNgwee,
            $tenor,
            $earned,
            $monthsAvailable,
            $lockdown,
            $openLoan !== null,
            $overriding && $openLoan !== null,
        );
    }

    /** The most a member could borrow right now, before any other condition. */
    public function ceilingFor(Member $member, ?CarbonInterface $on = null): Money
    {
        $month = $member->cycle->monthFor($on ?? Carbon::today());

        if ($month === null) {
            return Kwacha::zero();
        }

        return $this->savings->cumulativeSavings($member, $month)
            ->multipliedBy($member->cycle->max_loan_multiple);
    }

    /** Whether the member currently holds a loan that blocks another request. */
    public function hasOpenLoan(Member $member): bool
    {
        return $member->loans()->blocking()->exists();
    }

    protected function lockdownMonthLabel(Member $member): string
    {
        $month = $member->cycle->monthAt($member->cycle->loan_lockdown_starts_month);

        return $month?->month->format('F') ?? 'the lockdown month';
    }
}
