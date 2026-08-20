<?php

namespace App\Http\Controllers\App;

use App\Domain\Cycles\CurrentCycle;
use App\Domain\Reporting\LoanMatrix;
use App\Exports\LoanMatrixExport;
use App\Http\Controllers\Controller;
use App\Models\Loan;
use App\Support\Kwacha;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response;

/**
 * Downloads the loans ledger as the group keeps it.
 *
 * Both formats are generated on the server from the same LoanMatrix the screen renders,
 * so an exported sheet can never disagree with what the committee just saw.
 */
class LoanExportController extends Controller
{
    public function __construct(protected LoanMatrix $matrix) {}

    public function __invoke(Request $request, CurrentCycle $currentCycle, string $format): Response
    {
        $this->authorize('viewAny', Loan::class);

        $cycle = $currentCycle->getOrFail();
        $through = $request->integer('through') ?: null;
        $filename = 'unity-loans-'.$cycle->starts_on->format('Y').'-'.Carbon::now()->format('Ymd');

        if ($format === 'xlsx') {
            return Excel::download(
                new LoanMatrixExport($cycle, $this->matrix, $through),
                "{$filename}.xlsx",
            );
        }

        return Pdf::loadView('pdf.loan-matrix', [
            'cycle' => $cycle,
            'matrix' => $this->matrix->for($cycle, $through),
            'generatedAt' => Carbon::now(),
            'money' => fn (int $ngwee): string => Kwacha::format($ngwee),
        ])
            ->setPaper('a3', 'landscape')
            ->download("{$filename}.pdf");
    }
}
