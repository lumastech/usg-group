<?php

namespace App\Http\Controllers\App;

use App\Domain\Cycles\CurrentCycle;
use App\Domain\Reporting\SocialFundOverview;
use App\Exports\SocialFundLedgerExport;
use App\Http\Controllers\Controller;
use App\Models\SocialFundTransaction;
use App\Support\Kwacha;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response;

/**
 * Downloads the fund's ledger the way the group keeps it.
 *
 * Both formats are built on the server from the same overview the screen renders, so
 * the sheet and the dashboard can never show different balances.
 */
class SocialFundExportController extends Controller
{
    public function __construct(protected SocialFundOverview $overview) {}

    public function __invoke(Request $request, CurrentCycle $currentCycle, string $format): Response
    {
        $this->authorize('viewAny', SocialFundTransaction::class);

        $cycle = $currentCycle->getOrFail();
        $filename = 'unity-social-fund-'.$cycle->starts_on->format('Y').'-'.Carbon::now()->format('Ymd');

        if ($format === 'xlsx') {
            return Excel::download(new SocialFundLedgerExport($cycle, $this->overview), "{$filename}.xlsx");
        }

        return Pdf::loadView('pdf.social-fund-ledger', [
            'cycle' => $cycle,
            'overview' => $this->overview->for($cycle),
            'entries' => SocialFundTransaction::query()->forCycle($cycle)
                ->with('member', 'recordedBy', 'secondApprover')
                ->orderBy('occurred_on')->orderBy('id')->get(),
            'generatedAt' => Carbon::now(),
            'money' => fn (int $ngwee): string => Kwacha::format($ngwee),
        ])
            ->setPaper('a4', 'landscape')
            ->download("{$filename}.pdf");
    }
}
