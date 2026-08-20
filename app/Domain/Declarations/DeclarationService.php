<?php

namespace App\Domain\Declarations;

use App\Domain\Loans\LoanEligibilityService;
use App\Domain\Loans\ScheduledRepayments;
use App\Domain\Savings\SavingsLedger;
use App\Enums\DeclarationStatus;
use App\Exceptions\DeclarationLockedException;
use App\Exceptions\DeclarationWindowClosedException;
use App\Exceptions\LoanNotEligibleException;
use App\Models\CycleMonth;
use App\Models\Declaration;
use App\Models\Member;
use App\Support\Kwacha;
use Brick\Money\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Captures what a member intends to bring to, and take from, the month's table.
 *
 * A declaration moves no money. It is the promise the trading session is then run
 * against, which is why the rules enforced here are the same rules the savings ledger
 * and the loan desk will enforce on the day: declaring K537 of savings or a loan the
 * member cannot carry would only produce a refusal at the table, and the member would
 * find out three days too late to fix it.
 */
class DeclarationService
{
    public function __construct(
        protected DeclarationWindow $window,
        protected SavingsLedger $savings,
        protected LoanEligibilityService $eligibility,
        protected ScheduledRepayments $repayments,
    ) {}

    /**
     * Records or replaces a member's declaration for one month.
     *
     * `$onBehalf` is the treasurer's late-entry path: it lets a declaration be captured
     * after the window has closed, stamped late so the export and the console both show
     * it as one. It never opens the window early — before 08:00 on the 1st there is
     * nothing for anybody to capture.
     */
    public function submit(
        Member $member,
        CycleMonth $month,
        Money $saving,
        Money $repayment,
        Money $loanRequested,
        Member $actor,
        bool $onBehalf = false,
        ?CarbonInterface $at = null,
        ?string $note = null,
    ): Declaration {
        $at ??= Carbon::now();

        $this->assertWindowAccepts($month, $onBehalf, $at);

        $existing = $this->find($member, $month);

        if ($existing !== null && ! $existing->status->isEditable()) {
            throw new DeclarationLockedException(
                "The declaration for {$month->label()} is ".strtolower($existing->status->label())
                    .' and can no longer be changed.'
            );
        }

        $this->assertAmountsAllowed($member, $month, $saving, $loanRequested, $at);

        $total = Kwacha::toNgwee($saving) + Kwacha::toNgwee($repayment) - Kwacha::toNgwee($loanRequested);

        return DB::transaction(function () use (
            $member, $month, $saving, $repayment, $loanRequested, $actor, $at, $note, $total, $existing
        ): Declaration {
            $declaration = $existing ?? new Declaration([
                'cycle_id' => $month->cycle_id,
                'cycle_month_id' => $month->id,
                'member_id' => $member->id,
            ]);

            $declaration->fill([
                'cycle_id' => $month->cycle_id,
                'cycle_month_id' => $month->id,
                'member_id' => $member->id,
                'saving_amount_ngwee' => $saving,
                'loan_repayment_amount_ngwee' => $repayment,
                'loan_requested_amount_ngwee' => $loanRequested,
                'total_expected_payment_ngwee' => Kwacha::ofNgwee($total),
                'submitted_at' => $at,
                'is_late' => $this->window->isLate($month, $at),
                'status' => DeclarationStatus::Submitted,
                'recorded_by_member_id' => $actor->id,
                'note' => $note,
            ])->save();

            return $declaration->refresh();
        });
    }

    /** The member's declaration for a month, or null when they have not made one. */
    public function find(Member $member, CycleMonth $month): ?Declaration
    {
        return Declaration::query()
            ->where('member_id', $member->id)
            ->where('cycle_month_id', $month->id)
            ->first();
    }

    /**
     * The figures the form should open with.
     *
     * @return array{saving_amount_ngwee: int, loan_repayment_amount_ngwee: int, loan_requested_amount_ngwee: int}
     */
    public function defaultsFor(Member $member, CycleMonth $month): array
    {
        $existing = $this->find($member, $month);

        if ($existing !== null) {
            return [
                'saving_amount_ngwee' => $existing->getRawOriginal('saving_amount_ngwee'),
                'loan_repayment_amount_ngwee' => $existing->getRawOriginal('loan_repayment_amount_ngwee'),
                'loan_requested_amount_ngwee' => $existing->getRawOriginal('loan_requested_amount_ngwee'),
            ];
        }

        return [
            'saving_amount_ngwee' => Kwacha::toNgwee($month->cycle->min_savings_ngwee),
            'loan_repayment_amount_ngwee' => $this->repayments->dueNgwee($member, $month),
            'loan_requested_amount_ngwee' => 0,
        ];
    }

    /**
     * Closes the window on a month: every declaration becomes read-only.
     *
     * Called when the trading session opens, so the figures the session was built from
     * cannot move underneath it.
     */
    public function lockMonth(CycleMonth $month): int
    {
        return Declaration::query()
            ->forMonth($month)
            ->where('status', DeclarationStatus::Submitted->value)
            ->update(['status' => DeclarationStatus::Locked->value]);
    }

    /**
     * The active members who have not declared for a month.
     *
     * @return Collection<int, Member>
     */
    public function missingFor(CycleMonth $month): Collection
    {
        $declared = Declaration::query()->forMonth($month)->pluck('member_id')->all();

        return $month->cycle->members()->active()->get()
            ->reject(fn (Member $member): bool => in_array($member->id, $declared, true))
            ->values();
    }

    /**
     * The window rules, which differ for a member and for a treasurer capturing late.
     */
    protected function assertWindowAccepts(CycleMonth $month, bool $onBehalf, CarbonInterface $at): void
    {
        if ($this->window->isBeforeOpen($month, $at)) {
            throw new DeclarationWindowClosedException(
                'Declarations for '.$month->label().' open on '
                    .$month->declarations_open_at->format('j F Y \a\t H:i').'.'
            );
        }

        if (! $this->window->isOpen($month, $at) && ! $onBehalf) {
            throw new DeclarationWindowClosedException(
                'Declarations for '.$month->label().' closed on '
                    .$month->declarations_close_at->format('j F Y').'. Ask the treasurer to capture a late declaration.'
            );
        }
    }

    /**
     * The savings and the loan request are checked against the modules that own them.
     *
     * The repayment is not: it is a promise to pay what the schedule already says is
     * due, and a member paying more or less than that is a matter for the trading
     * table, not a reason to refuse the declaration.
     */
    protected function assertAmountsAllowed(
        Member $member,
        CycleMonth $month,
        Money $saving,
        Money $loanRequested,
        CarbonInterface $at,
    ): void {
        $this->savings->assertValidContribution($member, $month, $saving);

        if (Kwacha::toNgwee($loanRequested) <= 0) {
            return;
        }

        $eligibility = $this->eligibility->check($member, $loanRequested, $month->trading_starts_on);

        if ($eligibility->failed()) {
            throw LoanNotEligibleException::from($eligibility);
        }
    }
}
