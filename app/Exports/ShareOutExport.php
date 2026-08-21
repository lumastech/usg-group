<?php

namespace App\Exports;

use App\Domain\Reporting\ShareOutSheet;
use App\Models\Cycle;
use App\Support\Kwacha;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * The SHARE OUT sheet, exported column for column as the workbook holds it.
 *
 * Two header rows — the month, then Savings and Interest beneath it — a line per
 * member, then the six closing columns, and the totals row at the foot that the
 * committee reads out. Amounts are written as Kwacha numbers rather than formatted
 * strings so the sheet still sums once it is opened.
 */
class ShareOutExport implements FromArray, ShouldAutoSize, WithStyles, WithTitle
{
    public function __construct(
        protected Cycle $cycle,
        protected ShareOutSheet $sheet,
    ) {}

    public function title(): string
    {
        return 'SHARE OUT';
    }

    /**
     * @return array<int, array<int, string|float|null>>
     */
    public function array(): array
    {
        $data = $this->sheet->for($this->cycle);

        return [
            ...$this->headerRows($data['months']),
            ...$this->memberRows($data['months'], $data['rows']),
            $this->totalsRow($data['months'], $data['totals']),
        ];
    }

    /** The six closing columns, named as the workbook names them. */
    protected function closingHeadings(): array
    {
        return [
            'Total savings',
            'Total interest',
            'Outstanding loan',
            'Net value',
            'Round-off adjustment',
            'Net payable',
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $months
     * @return array<int, array<int, string|null>>
     */
    protected function headerRows(array $months): array
    {
        $top = ['', 'Member', ''];
        $bottom = ['#', '', 'Case'];

        foreach ($months as $month) {
            $top[] = $month['full_label'];
            $top[] = '';
            $bottom[] = 'Savings';
            $bottom[] = 'Interest';
        }

        return [
            [...$top, ...$this->closingHeadings()],
            [...$bottom, ...array_fill(0, count($this->closingHeadings()), '')],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $months
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<int, string|float|null>>
     */
    protected function memberRows(array $months, array $rows): array
    {
        return array_map(function (array $row) use ($months): array {
            $line = [$row['member_number'], $row['full_name'], $row['case_label']];

            foreach ($months as $month) {
                $cell = $row['cells'][$month['id']] ?? ['savings' => 0, 'interest' => 0];

                $line[] = $this->kwacha($cell['savings']);
                $line[] = $this->kwacha($cell['interest']);
            }

            return [
                ...$line,
                $this->kwacha($row['total_savings_ngwee']),
                $this->kwacha($row['total_interest_ngwee']),
                $this->kwacha($row['outstanding_loan_ngwee']),
                $this->kwacha($row['net_value_ngwee']),
                $this->kwacha($row['round_off_ngwee']),
                $this->kwacha($row['net_payable_ngwee']),
            ];
        }, $rows);
    }

    /**
     * @param  array<int, array<string, mixed>>  $months
     * @param  array<string, mixed>  $totals
     * @return array<int, string|float|null>
     */
    protected function totalsRow(array $months, array $totals): array
    {
        $line = ['', 'TOTAL', ''];

        foreach ($months as $month) {
            $line[] = $this->kwacha($totals['months'][$month['id']]['savings'] ?? 0);
            $line[] = $this->kwacha($totals['months'][$month['id']]['interest'] ?? 0);
        }

        return [
            ...$line,
            $this->kwacha($totals['total_savings_ngwee']),
            $this->kwacha($totals['total_interest_ngwee']),
            $this->kwacha($totals['outstanding_loan_ngwee']),
            $this->kwacha($totals['net_value_ngwee']),
            $this->kwacha($totals['round_off_ngwee']),
            $this->kwacha($totals['net_payable_ngwee']),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function styles(Worksheet $sheet): array
    {
        $lastRow = $sheet->getHighestRow();

        return [
            1 => ['font' => ['bold' => true]],
            2 => ['font' => ['bold' => true]],
            $lastRow => ['font' => ['bold' => true]],
        ];
    }

    /** Sheets hold Kwacha, the unit the group thinks in; the app stores ngwee. */
    protected function kwacha(int $ngwee): float
    {
        return round($ngwee / Kwacha::NGWEE_PER_KWACHA, 2);
    }
}
