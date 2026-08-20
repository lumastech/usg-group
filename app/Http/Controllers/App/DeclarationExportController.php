<?php

namespace App\Http\Controllers\App;

use App\Domain\Cycles\CurrentCycle;
use App\Domain\Declarations\DeclarationSheet;
use App\Exports\DeclarationsExport;
use App\Http\Controllers\Controller;
use App\Models\Declaration;
use App\Support\Kwacha;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response;

/**
 * Downloads one month's declarations, in either format.
 *
 * Both are generated from the same DeclarationSheet the screen renders, so an exported
 * sheet can never disagree with what the treasurer just read on the console.
 */
class DeclarationExportController extends Controller
{
    public function __construct(protected DeclarationSheet $sheet) {}

    public function __invoke(Request $request, CurrentCycle $currentCycle, string $format): Response
    {
        $this->authorize('export', Declaration::class);

        $cycle = $currentCycle->getOrFail();
        $month = ($request->integer('month') ?: null) === null
            ? $cycle->monthFor(now())
            : $cycle->monthAt($request->integer('month'));

        abort_if($month === null, 404);

        $filename = 'unity-declarations-'.$month->month->format('Y-m');

        if ($format === 'xlsx') {
            return Excel::download(new DeclarationsExport($month, $this->sheet), "{$filename}.xlsx");
        }

        $data = $this->sheet->for($month);

        return Pdf::loadView('pdf.declarations', [
            'cycle' => $cycle,
            'month' => $month,
            'rows' => $data['rows'],
            'totals' => $data['totals'],
            'generatedAt' => Carbon::now(),
            'money' => fn (int $ngwee): string => Kwacha::format($ngwee),
        ])
            ->setPaper('a4', 'landscape')
            ->download("{$filename}.pdf");
    }
}
