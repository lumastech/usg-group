<?php

namespace App\Http\Controllers\App;

use App\Domain\Cycles\CurrentCycle;
use App\Domain\Reporting\SavingsStatementPdf;
use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\SavingsTransaction;
use Symfony\Component\HttpFoundation\Response;

/** A member's statement, downloaded by the committee on their behalf. */
class SavingsStatementController extends Controller
{
    public function __invoke(Member $member, CurrentCycle $currentCycle, SavingsStatementPdf $pdf): Response
    {
        $this->authorize('viewAny', SavingsTransaction::class);

        return $pdf->for($currentCycle->getOrFail(), $member);
    }
}
