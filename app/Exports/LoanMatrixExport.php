<?php

namespace App\Exports;

use App\Domain\Reporting\LoanMatrix;
use App\Models\Cycle;
use App\Support\Kwacha;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * The LOANS sheet, exported the way the group already reads it.
 *
 * Two header rows — the month, then Borrowed and Balance beneath it — a line per member
 * and a totals row at the foot, matching the workbook the group kept by hand.
 *
 * Amounts are written as numbers in Kwacha rather than formatted strings, so the sheet
 * stays usable for sums and charts once it is opened.
 */
class LoanMatrixExport implements FromArray, ShouldAutoSize, WithStyles, WithTitle
{
    public function __construct(
        protected Cycle $cycle,
        protected LoanMatrix $matrix,
        protected ?int $throughSequence = null,
    ) {}

    public function title(): string
    {
        return 'LOANS';
    }

    /**
     * @return array<int, array<int, string|float|null>>
     */
    public function array(): array
    {
        $data = $this->matrix->for($this->cycle, $this->throughSequence);

        return [
            ...$this->headerRows($data['months']),
            ...$this->memberRows($data['months'], $data['rows']),
            $this->totalsRow($data['months'], $data['totals']),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $months
     * @return array<int, array<int, string|null>>
     */
    protected function headerRows(array $months): array
    {
        $top = ['', 'Member'];
        $bottom = ['#', ''];

        foreach ($months as $month) {
            $top[] = $month['full_label'];
            $top[] = '';
            $bottom[] = 'Borrowed';
            $bottom[] = 'Balance';
        }

        return [
            [...$top, 'Borrowed', 'Interest paid', 'Penalties', 'Balance'],
            [...$bottom, '', '', '', ''],
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
            $line = [$row['member_number'], $row['full_name']];

            foreach ($months as $month) {
                $cell = $row['cells'][$month['id']] ?? ['borrowed' => 0, 'balance' => 0];

                $line[] = $this->kwacha($cell['borrowed']);
                $line[] = $this->kwacha($cell['balance']);
            }

            return [
                ...$line,
                $this->kwacha($row['borrowed_ngwee']),
                $this->kwacha($row['interest_paid_ngwee']),
                $this->kwacha($row['penalties_ngwee']),
                $this->kwacha($row['balance_ngwee']),
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
        $line = ['', 'TOTAL'];

        foreach ($months as $month) {
            $line[] = $this->kwacha($totals['months'][$month['id']]['borrowed'] ?? 0);
            $line[] = $this->kwacha($totals['months'][$month['id']]['balance'] ?? 0);
        }

        return [
            ...$line,
            $this->kwacha($totals['borrowed_ngwee']),
            $this->kwacha($totals['interest_paid_ngwee']),
            $this->kwacha($totals['penalties_ngwee']),
            $this->kwacha($totals['balance_ngwee']),
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
