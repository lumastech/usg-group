<?php

namespace App\Exports;

use App\Domain\Declarations\DeclarationSheet;
use App\Models\CycleMonth;
use App\Support\Kwacha;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * The DECLARATIONS sheet for one month, exported the way the group already reads it.
 *
 * The total expected payment is written signed: a member drawing a loan larger than
 * what they are bringing shows a negative figure, exactly as the workbook does.
 */
class DeclarationsExport implements FromArray, ShouldAutoSize, WithStyles, WithTitle
{
    public function __construct(protected CycleMonth $month, protected DeclarationSheet $sheet) {}

    public function title(): string
    {
        return 'DECLARATIONS';
    }

    /**
     * @return array<int, array<int, string|float|null>>
     */
    public function array(): array
    {
        $data = $this->sheet->for($this->month);

        return [
            ['Unity Savings Group — Declarations', $this->month->label()],
            [],
            ['#', 'Member', 'Savings', 'Loan repayment', 'Loan requested',
                'Total expected payment', 'Submitted', 'Late', 'Status'],
            ...array_map(fn (array $row): array => $this->memberRow($row), $data['rows']),
            $this->totalsRow($data['totals']),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<int, string|float|null>
     */
    protected function memberRow(array $row): array
    {
        if (! $row['declared']) {
            return [$row['member_number'], $row['full_name'], null, null, null, null, null, null, 'Not declared'];
        }

        return [
            $row['member_number'],
            $row['full_name'],
            $this->kwacha($row['saving_ngwee']),
            $this->kwacha($row['repayment_ngwee']),
            $this->kwacha($row['requested_ngwee']),
            $this->kwacha($row['total_ngwee']),
            $row['submitted_at'],
            $row['is_late'] ? 'Yes' : 'No',
            $row['status_label'],
        ];
    }

    /**
     * @param  array<string, int>  $totals
     * @return array<int, string|float|null>
     */
    protected function totalsRow(array $totals): array
    {
        return [
            '',
            'TOTAL',
            $this->kwacha($totals['saving_ngwee']),
            $this->kwacha($totals['repayment_ngwee']),
            $this->kwacha($totals['requested_ngwee']),
            $this->kwacha($totals['total_ngwee']),
            null,
            null,
            null,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
            3 => ['font' => ['bold' => true]],
            $sheet->getHighestRow() => ['font' => ['bold' => true]],
        ];
    }

    /** Sheets hold Kwacha, the unit the group thinks in; the app stores ngwee. */
    protected function kwacha(int $ngwee): float
    {
        return round($ngwee / Kwacha::NGWEE_PER_KWACHA, 2);
    }
}
