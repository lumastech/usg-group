<?php

namespace App\Exports;

use App\Domain\Reporting\SocialFundOverview;
use App\Models\Cycle;
use App\Models\SocialFundTransaction;
use App\Support\Kwacha;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * The SOCIAL FUND sheet: every entry in date order with a running balance.
 *
 * A month-by-month summary sits above the ledger, because that is how the group reads
 * the fund — what came in, what went out, what is left at the end of each month.
 *
 * Amounts are written as numbers in Kwacha rather than formatted strings, so the sheet
 * stays usable for sums once it is opened.
 */
class SocialFundLedgerExport implements FromArray, ShouldAutoSize, WithStyles, WithTitle
{
    /** Rows of summary above the ledger, used to place the bold headings. */
    protected int $ledgerHeaderRow = 0;

    public function __construct(
        protected Cycle $cycle,
        protected SocialFundOverview $overview,
    ) {}

    public function title(): string
    {
        return 'SOCIAL FUND';
    }

    /**
     * @return array<int, array<int, string|float|null>>
     */
    public function array(): array
    {
        $overview = $this->overview->for($this->cycle);

        $rows = [
            ['Month', 'In', 'Out', 'Closing balance'],
        ];

        foreach ($overview['months'] as $month) {
            $rows[] = [
                $month['label'],
                $this->kwacha($month['in_ngwee']),
                $this->kwacha($month['out_ngwee']),
                $this->kwacha($month['closing_ngwee']),
            ];
        }

        $rows[] = [
            'TOTAL',
            $this->kwacha($overview['inflow_ngwee']),
            $this->kwacha($overview['outflow_ngwee']),
            $this->kwacha($overview['balance_ngwee']),
        ];

        $rows[] = [];

        $this->ledgerHeaderRow = count($rows) + 1;

        $rows[] = ['Date', 'Type', 'Member', 'Amount', 'Balance', 'Recorded by', 'Second approver', 'Note'];

        $running = 0;

        foreach ($this->entries() as $entry) {
            $running += $entry->getRawOriginal('amount_ngwee');

            $rows[] = [
                $entry->occurred_on->toDateString(),
                $entry->type->label(),
                $entry->member?->full_name,
                $this->kwacha($entry->getRawOriginal('amount_ngwee')),
                $this->kwacha($running),
                $entry->recordedBy?->full_name,
                $entry->secondApprover?->full_name,
                $entry->note,
            ];
        }

        return $rows;
    }

    /**
     * @return Collection<int, SocialFundTransaction>
     */
    protected function entries(): Collection
    {
        return SocialFundTransaction::query()
            ->forCycle($this->cycle)
            ->with('member', 'recordedBy', 'secondApprover')
            ->orderBy('occurred_on')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
            $this->ledgerHeaderRow => ['font' => ['bold' => true]],
        ];
    }

    /** Sheets hold Kwacha, the unit the group thinks in; the app stores ngwee. */
    protected function kwacha(int $ngwee): float
    {
        return round($ngwee / Kwacha::NGWEE_PER_KWACHA, 2);
    }
}
