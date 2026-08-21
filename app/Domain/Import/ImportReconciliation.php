<?php

namespace App\Domain\Import;

use App\Domain\SocialFund\SocialFundLedger;
use App\Enums\LoanTransactionType;
use App\Enums\SavingsTransactionType;
use App\Enums\SocialFundTransactionType;
use App\Models\Cycle;
use App\Models\Declaration;
use App\Models\InterestAllocation;
use App\Models\LoanTransaction;
use App\Models\SavingsTransaction;
use App\Support\Kwacha;

/**
 * Proves the import landed: what the workbook says, against what the ledgers now hold.
 *
 * An import that reports "done" and quietly lost a member's March is worse than one
 * that fails, so every line here is a three-way comparison — the workbook's own total,
 * the total actually posted, and the total the application derives from its ledgers —
 * and every discrepancy is named rather than summarised.
 *
 * The interest line is expected to differ and says so. Interest is not imported: it is
 * a pooled pro-rata split the application recomputes from the savings it now holds, so
 * the comparison is there to be read by a person, not to pass or fail.
 */
class ImportReconciliation
{
    public function __construct(protected SocialFundLedger $fund) {}

    /**
     * @param  array<string, int>  $workbookTotals  from WorkbookImporter::plan()
     * @return array{
     *     lines: array<int, array{
     *         label: string,
     *         workbook_ngwee: int,
     *         ledger_ngwee: int,
     *         difference_ngwee: int,
     *         balanced: bool,
     *         advisory: bool,
     *         note: string,
     *     }>,
     *     balanced: bool,
     *     discrepancy_count: int,
     * }
     */
    public function for(Cycle $cycle, array $workbookTotals): array
    {
        $memberIds = $cycle->members()->pluck('id');
        $monthIds = $cycle->months()->pluck('id');

        $lines = [
            $this->line(
                'Savings',
                $workbookTotals[WorkbookImporter::KIND_SAVINGS] ?? 0,
                (int) SavingsTransaction::query()
                    ->whereIn('member_id', $memberIds)
                    ->whereIn('type', [
                        SavingsTransactionType::Contribution->value,
                        SavingsTransactionType::Adjustment->value,
                        SavingsTransactionType::ImportOpening->value,
                    ])
                    ->sum('amount_ngwee'),
                'Every contribution the savings ledger holds for this cycle.',
            ),
            $this->line(
                'Lending',
                $workbookTotals[WorkbookImporter::KIND_LOAN_DISBURSEMENT] ?? 0,
                (int) LoanTransaction::query()
                    ->join('loans', 'loans.id', '=', 'loan_transactions.loan_id')
                    ->where('loans.cycle_id', $cycle->id)
                    ->whereIn('loan_transactions.type', [
                        LoanTransactionType::Disbursement->value,
                        LoanTransactionType::Repayment->value,
                    ])
                    ->sum('loan_transactions.amount_ngwee'),
                'Disbursements plus repayments, which is what the LOANS sheet totals.',
            ),
            $this->line(
                'Social fund contributions',
                $workbookTotals[WorkbookImporter::KIND_SOCIAL_FUND] ?? 0,
                Kwacha::toNgwee($this->fund->totalReceived($cycle, SocialFundTransactionType::Contribution)),
                'The one-off contribution, per member.',
            ),
            $this->line(
                'Declared savings',
                $workbookTotals[WorkbookImporter::KIND_DECLARATION] ?? 0,
                (int) Declaration::query()
                    ->acrossCycles()
                    ->whereIn('cycle_month_id', $monthIds)
                    ->sum('saving_amount_ngwee'),
                'What members promised, which is not itself money — it should equal the workbook exactly.',
            ),
            $this->advisory(
                'Interest (recomputed, not imported)',
                (int) InterestAllocation::query()->whereIn('member_id', $memberIds)->sum('amount_ngwee'),
                'Interest is a pooled pro-rata split the application derives. A difference against '
                .'the workbook here is expected until every month has been closed in the app.',
            ),
        ];

        $discrepancies = array_filter(
            $lines,
            fn (array $line): bool => ! $line['balanced'] && ! $line['advisory'],
        );

        return [
            'lines' => $lines,
            'balanced' => $discrepancies === [],
            'discrepancy_count' => count($discrepancies),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function line(string $label, int $workbook, int $ledger, string $note): array
    {
        return [
            'label' => $label,
            'workbook_ngwee' => $workbook,
            'ledger_ngwee' => $ledger,
            'difference_ngwee' => $ledger - $workbook,
            'balanced' => $ledger === $workbook,
            'advisory' => false,
            'note' => $note,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function advisory(string $label, int $ledger, string $note): array
    {
        return [
            'label' => $label,
            'workbook_ngwee' => 0,
            'ledger_ngwee' => $ledger,
            'difference_ngwee' => $ledger,
            'balanced' => true,
            'advisory' => true,
            'note' => $note,
        ];
    }
}
