<?php

namespace App\Http\Controllers\App;

use App\Domain\Cycles\CurrentCycle;
use App\Domain\Reporting\ShareOutSheet;
use App\Exports\ShareOutExport;
use App\Http\Controllers\Controller;
use App\Models\Payout;
use App\Support\Kwacha;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response;

/**
 * Downloads the SHARE OUT sheet.
 *
 * Both formats render the same ShareOutSheet the screen renders, so an exported sheet
 * can never disagree with what the committee just read out.
 */
class ShareOutExportController extends Controller
{
    public function __construct(protected ShareOutSheet $sheet) {}

    public function __invoke(CurrentCycle $currentCycle, string $format): Response
    {
        $this->authorize('viewAny', Payout::class);

        $cycle = $currentCycle->getOrFail();
        $filename = 'unity-share-out-'.$cycle->starts_on->format('Y').'-'.Carbon::now()->format('Ymd');

        if ($format === 'xlsx') {
            return Excel::download(new ShareOutExport($cycle, $this->sheet), "{$filename}.xlsx");
        }

        return Pdf::loadView('pdf.share-out', [
            'cycle' => $cycle,
            'sheet' => $this->sheet->for($cycle),
            'generatedAt' => Carbon::now(),
            'money' => fn (int $ngwee): string => Kwacha::format($ngwee),
        ])
            ->setPaper('a3', 'landscape')
            ->download("{$filename}.pdf");
    }
}
