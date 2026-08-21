<?php

namespace App\Domain\ShareOut;

use App\Domain\SocialFund\LatePenaltyMirror;
use App\Domain\SocialFund\SocialFundContributions;
use App\Domain\SocialFund\SocialFundLedger;
use App\Enums\CollateralClaimStatus;
use App\Enums\LoanStatus;
use App\Enums\SocialFundTransactionType;
use App\Enums\TradingSessionStatus;
use App\Models\CollateralClaim;
use App\Models\Cycle;
use App\Models\CycleMonth;
use App\Models\Loan;
use App\Models\Member;
use App\Models\TradingSession;
use App\Support\Kwacha;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * What still stands between the cycle and share-out.
 *
 * The group cannot divide money it has not finished counting, so the four things the
 * constitution wants settled before the last day are checked here rather than
 * remembered: every loan closed, every trading month posted, the two ledgers that
 * hold the late penalty agreeing, and nobody's contribution still outstanding.
 *
 * Nothing here writes. It is read by the checklist screen and again, for real, by
 * CycleCloser at the moment the transition is attempted — the screen can go stale in
 * the minutes between looking and signing, and only the second reading counts.
 */
class ShareOutPreflight
{
    public function __construct(
        protected LatePenaltyMirror $mirror,
        protected SocialFundLedger $fund,
        protected SocialFundContributions $contributions,
    ) {}

    /**
     * The whole checklist, in the order the committee works it.
     *
     * @return array<int, PreflightItem>
     */
    public function items(Cycle $cycle, ?CarbonInterface $asOf = null): array
    {
        $asOf ??= Carbon::today();

        return [
            $this->loansClosed($cycle),
            $this->sessionsConcluded($cycle, $asOf),
            $this->fundReconciled($cycle),
            $this->contributionsResolved($cycle),
        ];
    }

    /** Whether every check is clear, which is what opens the transition without an override. */
    public function passes(Cycle $cycle, ?CarbonInterface $asOf = null): bool
    {
        foreach ($this->items($cycle, $asOf) as $item) {
            if (! $item->passed) {
                return false;
            }
        }

        return true;
    }

    /**
     * The checklist as the screen reads it, with a summary the banner renders from.
     *
     * @return array{
     *     items: array<int, array<string, mixed>>,
     *     passed: bool,
     *     blocking_count: int,
     *     checked_at: string,
     * }
     */
    public function payload(Cycle $cycle, ?CarbonInterface $asOf = null): array
    {
        $items = $this->items($cycle, $asOf);
        $blocking = array_values(array_filter($items, fn (PreflightItem $item): bool => ! $item->passed));

        return [
            'items' => array_map(fn (PreflightItem $item): array => $item->toArray(), $items),
            'passed' => $blocking === [],
            'blocking_count' => count($blocking),
            'checked_at' => Carbon::now()->toIso8601String(),
        ];
    }

    /**
     * Every loan must have finished: settled, rejected, or defaulted with the
     * collateral actually enforced.
     *
     * A defaulted loan with a claim still sitting at draft or sign-off is the case this
     * catches — the group has written the debt off in its head but has not yet taken
     * what stands behind it, and the share-out arithmetic would quietly absorb the loss.
     */
    protected function loansClosed(Cycle $cycle): PreflightItem
    {
        $open = Loan::query()
            ->forCycle($cycle)
            ->whereNotIn('status', [LoanStatus::Settled->value, LoanStatus::Rejected->value])
            ->with('member')
            ->get();

        /* A collateral claim hangs off the loan, not the cycle, so it needs no scope. */
        $enforced = CollateralClaim::query()
            ->whereIn('loan_id', $open->modelKeys())
            ->where('status', CollateralClaimStatus::Enforced->value)
            ->pluck('loan_id')
            ->all();

        $blocking = $open
            ->reject(fn (Loan $loan): bool => $loan->status === LoanStatus::Defaulted
                && in_array($loan->id, $enforced, true))
            ->map(fn (Loan $loan): array => [
                'id' => $loan->id,
                'label' => $loan->member?->full_name ?? "Loan #{$loan->id}",
                'detail' => $loan->status->label(),
                'amount_ngwee' => Kwacha::toNgwee($loan->current_balance_ngwee),
                'href' => "/app/loans/{$loan->id}",
            ])
            ->values()
            ->all();

        $label = 'Every loan closed';
        $description = 'Loans must be settled, or defaulted with the collateral enforced.';
        $href = '/app/loans';

        return $blocking === []
            ? PreflightItem::clear('loans_closed', $label, $description, $href, 'No loan is still running.')
            : PreflightItem::blocked('loans_closed', $label, $description, $href,
                count($blocking).' loan(s) still open or defaulted without an enforced claim.', $blocking);
    }

    /**
     * Every month whose trading day has passed must have a concluded session.
     *
     * Concluding is the act that posts a month to the ledgers, so an unconcluded month
     * is savings and repayments that happened at the table but exist nowhere else.
     */
    protected function sessionsConcluded(Cycle $cycle, CarbonInterface $asOf): PreflightItem
    {
        $concluded = TradingSession::query()
            ->acrossCycles()
            ->whereIn('cycle_month_id', $cycle->months()->pluck('id'))
            ->where('status', TradingSessionStatus::Concluded->value)
            ->pluck('cycle_month_id')
            ->all();

        $blocking = $cycle->months()
            ->get()
            ->filter(fn (CycleMonth $month): bool => $month->trading_concludes_on->lessThanOrEqualTo($asOf)
                && ! in_array($month->id, $concluded, true))
            ->map(fn (CycleMonth $month): array => [
                'id' => $month->id,
                'label' => $month->label(),
                'detail' => 'Trading day was '.$month->trading_concludes_on->format('j M Y'),
                'href' => '/app/trading',
            ])
            ->values()
            ->all();

        $label = 'Every trading month concluded';
        $description = 'Concluding a session is what posts the month to the ledgers.';
        $href = '/app/trading';

        return $blocking === []
            ? PreflightItem::clear('sessions_concluded', $label, $description, $href, 'Every month that has traded is posted.')
            : PreflightItem::blocked('sessions_concluded', $label, $description, $href,
                count($blocking).' month(s) have traded but were never concluded.', $blocking);
    }

    /**
     * The loan ledger and the Social Fund must still agree about the late penalty.
     *
     * Only the daily late-transfer penalty is mirrored into the fund; the 10%
     * missed-installment charge stays with the lending pool. Comparing anything else
     * here would make the reconciliation fail forever.
     */
    protected function fundReconciled(Cycle $cycle): PreflightItem
    {
        $unmirrored = $this->mirror->unmirrored($cycle->id);
        $charged = Kwacha::toNgwee($this->mirror->chargedOnLoans($cycle->id));
        $received = Kwacha::toNgwee($this->fund->totalReceived($cycle, SocialFundTransactionType::LatePenaltyInflow));

        $blocking = $unmirrored
            ->map(fn ($penalty): array => [
                'id' => $penalty->id,
                'label' => "Loan #{$penalty->loan_id}",
                'detail' => 'Penalty of '.Kwacha::format($penalty->amount_ngwee)
                    .' charged '.$penalty->occurred_on->format('j M Y').' never reached the fund',
                'href' => "/app/loans/{$penalty->loan_id}",
            ])
            ->values()
            ->all();

        if ($blocking === [] && $charged !== $received) {
            $blocking[] = [
                'id' => 0,
                'label' => 'Totals differ',
                'detail' => 'Loans charged '.Kwacha::format($charged).' but the fund received '.Kwacha::format($received),
                'href' => '/app/fund/ledger',
            ];
        }

        $label = 'Social fund reconciled';
        $description = 'Late-transfer penalties charged on loans must all be mirrored into the fund.';
        $href = '/app/fund/ledger';

        return $blocking === []
            ? PreflightItem::clear('fund_reconciled', $label, $description, $href,
                'Both ledgers agree at '.Kwacha::format($charged).'.')
            : PreflightItem::blocked('fund_reconciled', $label, $description, $href,
                'The loan ledger and the fund disagree. Run unity:reconcile-social-fund --fix.', $blocking);
    }

    /**
     * Nobody may still owe the group their joining fee or their Social Fund contribution.
     *
     * Both are one-off dues, not savings, so an unpaid one is not netted out of a
     * share-out — it simply has to be collected before the money is divided.
     */
    protected function contributionsResolved(Cycle $cycle): PreflightItem
    {
        $blocking = $this->contributions->outstanding($cycle)
            ->map(fn (Member $member): array => [
                'id' => $member->id,
                'label' => $member->full_name,
                'detail' => 'Social fund contribution unpaid',
                'href' => "/app/members/{$member->id}",
            ])
            ->concat(
                $cycle->members()->active()->where('joining_fee_paid', false)->get()
                    ->map(fn (Member $member): array => [
                        'id' => $member->id,
                        'label' => $member->full_name,
                        'detail' => 'Joining fee unpaid',
                        'href' => "/app/members/{$member->id}",
                    ])
            )
            ->values()
            ->all();

        $label = 'Contributions collected';
        $description = 'Joining fees and social fund contributions are dues, not savings — they are collected, never netted off.';
        $href = '/app/members';

        return $blocking === []
            ? PreflightItem::clear('contributions_resolved', $label, $description, $href, 'Every member is paid up.')
            : PreflightItem::blocked('contributions_resolved', $label, $description, $href,
                count($blocking).' contribution(s) still outstanding.', $blocking);
    }
}
