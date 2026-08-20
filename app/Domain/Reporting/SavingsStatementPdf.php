<?php

namespace App\Domain\Reporting;

use App\Models\Cycle;
use App\Models\Member;
use App\Support\Kwacha;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Renders one member's savings statement.
 *
 * Shared by the member portal and the committee's drill-down so a member and their
 * treasurer are always looking at the same document.
 */
class SavingsStatementPdf
{
    public function __construct(protected SavingsMatrix $matrix) {}

    public function for(Cycle $cycle, Member $member): Response
    {
        $own = $this->matrix->forMember($cycle, $member);

        $filename = 'savings-statement-'.$member->member_number.'-'.Carbon::now()->format('Ymd').'.pdf';

        return Pdf::loadView('pdf.member-statement', [
            'cycle' => $cycle,
            'member' => $member,
            'history' => $own['months'],
            'totals' => $own['totals'],
            'generatedAt' => Carbon::now(),
            'money' => fn (int $ngwee): string => Kwacha::format($ngwee),
        ])->download($filename);
    }
}
