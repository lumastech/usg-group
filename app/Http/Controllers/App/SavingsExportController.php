<?php

namespace App\Http\Controllers\App;

use App\Domain\Cycles\CurrentCycle;
use App\Domain\Reporting\SavingsMatrix;
use App\Exports\SavingsMatrixExport;
use App\Http\Controllers\Controller;
use App\Models\SavingsTransaction;
use App\Support\Kwacha;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response;

/**
 * Downloads the savings ledger as the group keeps it.
 *
 * Both formats are generated on the server from the same SavingsMatrix the screen
 * renders, so an exported sheet can never disagree with what the treasurer just saw.
 */
class SavingsExportController extends Controller
{
    public function __construct(protected SavingsMatrix $matrix) {}

    public function __invoke(Request $request, CurrentCycle $currentCycle, string $format): Response
    {
        $this->authorize('viewAny', SavingsTransaction::class);

        $cycle = $currentCycle->getOrFail();
        $through = $request->integer('through') ?: null;
        $filename = 'unity-savings-'.$cycle->starts_on->format('Y').'-'.Carbon::now()->format('Ymd');

        if ($format === 'xlsx') {
            return Excel::download(
                new SavingsMatrixExport($cycle, $this->matrix, $through),
                "{$filename}.xlsx",
            );
        }

        return Pdf::loadView('pdf.savings-matrix', [
            'cycle' => $cycle,
            'matrix' => $this->matrix->for($cycle, $through),
            'generatedAt' => Carbon::now(),
            'money' => fn (int $ngwee): string => Kwacha::format($ngwee),
        ])
            ->setPaper('a3', 'landscape')
            ->download("{$filename}.pdf");
    }
}
