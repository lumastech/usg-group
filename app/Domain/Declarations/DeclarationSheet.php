<?php

namespace App\Domain\Declarations;

use App\Models\CycleMonth;
use App\Models\Declaration;
use App\Models\Member;

/**
 * One month's declarations laid out as the workbook's DECLARATIONS sheet.
 *
 * Every active member gets a line whether or not they declared, because the blanks are
 * the point — the sheet is read out at the table to find who is missing. The screen,
 * the spreadsheet and the printable page all render from this one shape, so a treasurer
 * checking the export against the console is comparing the same numbers.
 */
class DeclarationSheet
{
    /**
     * @return array{
     *     rows: array<int, array<string, mixed>>,
     *     totals: array<string, int>,
     *     declared_count: int,
     *     missing_count: int
     * }
     */
    public function for(CycleMonth $month): array
    {
        $declarations = Declaration::query()->forMonth($month)->with('approvedBy')->get()->keyBy('member_id');

        $rows = [];
        $totals = ['saving_ngwee' => 0, 'repayment_ngwee' => 0, 'requested_ngwee' => 0, 'total_ngwee' => 0];
        $declared = 0;

        foreach ($month->cycle->members()->active()->get() as $member) {
            /** @var Declaration|null $declaration */
            $declaration = $declarations->get($member->id);

            if ($declaration !== null) {
                $declared++;
                $totals['saving_ngwee'] += $declaration->getRawOriginal('saving_amount_ngwee');
                $totals['repayment_ngwee'] += $declaration->getRawOriginal('loan_repayment_amount_ngwee');
                $totals['requested_ngwee'] += $declaration->getRawOriginal('loan_requested_amount_ngwee');
                $totals['total_ngwee'] += $declaration->totalExpectedNgwee();
            }

            $rows[] = $this->row($member, $declaration);
        }

        return [
            'rows' => $rows,
            'totals' => $totals,
            'declared_count' => $declared,
            'missing_count' => count($rows) - $declared,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function row(Member $member, ?Declaration $declaration): array
    {
        return [
            'member_id' => $member->id,
            'member_number' => $member->member_number,
            'full_name' => $member->full_name,
            'declared' => $declaration !== null,
            'declaration_id' => $declaration?->id,
            'saving_ngwee' => $declaration?->getRawOriginal('saving_amount_ngwee') ?? 0,
            'repayment_ngwee' => $declaration?->getRawOriginal('loan_repayment_amount_ngwee') ?? 0,
            'requested_ngwee' => $declaration?->getRawOriginal('loan_requested_amount_ngwee') ?? 0,
            'total_ngwee' => $declaration?->totalExpectedNgwee() ?? 0,
            'submitted_at' => $declaration?->submitted_at?->format('Y-m-d H:i'),
            'is_late' => (bool) $declaration?->is_late,
            /* The committee's "ask". Nothing may be collected from the member until
               this is stamped, so the sheet shows it beside the figures. */
            'approved' => (bool) $declaration?->isApproved(),
            'approved_at' => $declaration?->approved_at?->format('Y-m-d H:i'),
            'approved_by' => $declaration?->approvedBy?->full_name,
            'status' => $declaration?->status->value,
            'status_label' => $declaration?->status->label() ?? 'Not declared',
        ];
    }
}
