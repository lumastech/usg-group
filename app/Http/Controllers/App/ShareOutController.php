<?php

namespace App\Http\Controllers\App;

use App\Domain\Cycles\CurrentCycle;
use App\Domain\Reporting\ShareOutSheet;
use App\Domain\ShareOut\ShareOutBatchRunner;
use App\Http\Controllers\Controller;
use App\Models\Payout;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The SHARE OUT sheet: what every member walks away with.
 *
 * The screen reads and never writes. Its figures come from ShareOutSheet, which is the
 * same service the Excel and PDF exports render, so the sheet on the wall and the sheet
 * in the treasurer's hand cannot disagree.
 */
class ShareOutController extends Controller
{
    public function __construct(
        protected ShareOutSheet $sheet,
        protected ShareOutBatchRunner $runner,
    ) {}

    public function __invoke(Request $request, CurrentCycle $currentCycle): Response
    {
        $this->authorize('viewAny', Payout::class);

        $cycle = $currentCycle->get();

        if ($cycle === null) {
            return Inertia::render('app/shareout/Index', [
                'cycle' => null,
                'sheet' => null,
                'batch' => null,
                'abilities' => ['runBatch' => false],
            ]);
        }

        $sharingOut = $cycle->status->isSharingOut();

        return Inertia::render('app/shareout/Index', [
            'cycle' => [
                'id' => $cycle->id,
                'name' => $cycle->name,
                'status' => $cycle->status,
                'status_label' => $cycle->status->label(),
                'is_sharing_out' => $sharingOut,
            ],
            'sheet' => $this->sheet->for($cycle),
            /* Deferred: the batch preview recomputes every candidate's position, which
               is the expensive half of this page and not what it is opened for. */
            'batch' => Inertia::defer(fn (): array => [
                'candidates' => $this->runner->preview($cycle)->all(),
                'schedule' => $this->runner->schedule($cycle),
            ]),
            'abilities' => [
                'runBatch' => $sharingOut && $request->user()->can('payouts.execute'),
            ],
        ]);
    }
}
