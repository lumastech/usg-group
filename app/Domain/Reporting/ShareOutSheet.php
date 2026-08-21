<?php

namespace App\Domain\Reporting;

use App\Domain\Payouts\PayoutBreakdown;
use App\Domain\Payouts\PayoutCalculator;
use App\Enums\PayoutCase;
use App\Enums\PayoutLineKind;
use App\Models\Cycle;
use App\Models\Member;
use App\Models\Payout;
use App\Support\Kwacha;

/**
 * The SHARE OUT sheet of the group's workbook.
 *
 * Members down the side, a Savings and an Interest figure for every month across, then
 * the six columns the group reads out on the last day: Total Savings, Total Interest,
 * Outstanding Loan, Net Value, Round-off Adjustment and Net Payable.
 *
 * The month cells come from the same SavingsMatrix the savings screen renders, but the
 * closing columns come from PayoutCalculator rather than from arithmetic done here.
 * That matters: a member who left early forfeits their interest and an estate's
 * interest stops at the date of death, and those are rules that live in one place. The
 * sheet shows the interest they earned either way, because "what happened to my
 * interest?" is the first thing anybody asks — it is simply not carried into Net Value.
 */
class ShareOutSheet
{
    public function __construct(
        protected SavingsMatrix $savings,
        protected PayoutCalculator $calculator,
    ) {}

    /**
     * @return array{
     *     months: array<int, array<string, mixed>>,
     *     rows: array<int, array<string, mixed>>,
     *     totals: array<string, mixed>,
     * }
     */
    public function for(Cycle $cycle): array
    {
        $matrix = $this->savings->for($cycle);
        $settled = $this->settledPayouts($cycle);

        $rows = $cycle->members()->get()
            ->map(function (Member $member) use ($matrix, $settled): array {
                $line = collect($matrix['rows'])->firstWhere('member_id', $member->id) ?? [];

                return $this->row($member, $line, $settled[$member->id] ?? null);
            })
            ->values()
            ->all();

        return [
            'months' => $matrix['months'],
            'rows' => $rows,
            'totals' => $this->totals($matrix['months'], $rows),
        ];
    }

    /**
     * One member's line.
     *
     * @param  array<string, mixed>  $savingsRow
     * @return array<string, mixed>
     */
    protected function row(Member $member, array $savingsRow, ?Payout $payout): array
    {
        $breakdown = $this->calculator->for($member);
        $case = PayoutCase::forStatus($member->status);

        return [
            'member_id' => $member->id,
            'member_number' => $member->member_number,
            'full_name' => $member->full_name,
            'status' => $member->status,
            'status_label' => $member->status->label(),
            'case' => $case->value,
            'case_label' => $case->label(),
            'is_diaspora' => $member->is_diaspora,
            'cells' => $savingsRow['cells'] ?? [],
            'total_savings_ngwee' => $savingsRow['total_savings_ngwee'] ?? 0,
            'total_interest_ngwee' => $savingsRow['total_interest_ngwee'] ?? 0,
            'outstanding_loan_ngwee' => $this->outstandingLoanNgwee($breakdown),
            'net_value_ngwee' => $breakdown->netValueNgwee,
            'round_off_ngwee' => $breakdown->roundOffNgwee,
            'net_payable_ngwee' => $breakdown->netPayableNgwee,
            'is_negative' => $breakdown->isNegative(),
            'settled' => $payout !== null,
            'payout_id' => $payout?->id,
        ];
    }

    /**
     * What the member still owes, read back off the breakdown's own debit line.
     *
     * Taking it from the breakdown rather than querying the ledger again is what keeps
     * Net Value = Total − Loan true on the face of the sheet even for a death, where
     * the loan is struck on the date of death rather than today.
     */
    protected function outstandingLoanNgwee(PayoutBreakdown $breakdown): int
    {
        foreach ($breakdown->lines as $line) {
            if ($line->kind === PayoutLineKind::Debit) {
                /* Debit lines carry a negative amount; the sheet's column is positive. */
                return abs($line->amountNgwee);
            }
        }

        return 0;
    }

    /**
     * The footer. Every column is a plain sum of the lines above it, which is what the
     * tie-out test asserts against the ledgers.
     *
     * @param  array<int, array<string, mixed>>  $months
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    protected function totals(array $months, array $rows): array
    {
        $perMonth = [];

        foreach ($months as $month) {
            $perMonth[$month['id']] = [
                'savings' => array_sum(array_map(
                    fn (array $row): int => $row['cells'][$month['id']]['savings'] ?? 0,
                    $rows,
                )),
                'interest' => array_sum(array_map(
                    fn (array $row): int => $row['cells'][$month['id']]['interest'] ?? 0,
                    $rows,
                )),
            ];
        }

        return [
            'months' => $perMonth,
            'total_savings_ngwee' => array_sum(array_column($rows, 'total_savings_ngwee')),
            'total_interest_ngwee' => array_sum(array_column($rows, 'total_interest_ngwee')),
            'outstanding_loan_ngwee' => array_sum(array_column($rows, 'outstanding_loan_ngwee')),
            'net_value_ngwee' => array_sum(array_column($rows, 'net_value_ngwee')),
            'round_off_ngwee' => array_sum(array_column($rows, 'round_off_ngwee')),
            'net_payable_ngwee' => array_sum(array_column($rows, 'net_payable_ngwee')),
            'payable_ngwee' => array_sum(array_map(
                fn (array $row): int => max(0, $row['net_payable_ngwee']),
                $rows,
            )),
            'shortfall_ngwee' => array_sum(array_map(
                fn (array $row): int => max(0, -$row['net_payable_ngwee']),
                $rows,
            )),
            'members' => count($rows),
            'settled' => count(array_filter($rows, fn (array $row): bool => $row['settled'])),
        ];
    }

    /**
     * @return array<int, Payout> keyed by member id
     */
    protected function settledPayouts(Cycle $cycle): array
    {
        return Payout::query()
            ->acrossCycles()
            ->where('cycle_id', $cycle->id)
            ->get()
            ->keyBy('member_id')
            ->all();
    }

    /** Convenience for the PDF, which needs Kwacha strings rather than ngwee. */
    public function format(int $ngwee): string
    {
        return Kwacha::format($ngwee);
    }
}
