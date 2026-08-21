<?php

namespace App\Http\Controllers\App;

use App\Domain\Cycles\CurrentCycle;
use App\Domain\ShareOut\ShareOutBatchRunner;
use App\Exceptions\DomainRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\ShareOut\RunShareOutBatchRequest;
use App\Models\Payout;
use App\Support\Kwacha;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Settling the room, and the schedule the signatories take to the bank.
 *
 * The runner puts every member through the same PayoutExecutor a single closure goes
 * through — so a member who cannot be settled is reported back by name rather than
 * silently skipped, and the twenty-nine who could be are not rolled back with them.
 */
class ShareOutBatchController extends Controller
{
    public function __construct(protected ShareOutBatchRunner $runner) {}

    public function store(RunShareOutBatchRequest $request, CurrentCycle $currentCycle): RedirectResponse
    {
        $actor = $request->user()->member;

        if ($actor === null) {
            return back()->withErrors(['approver_email' => 'Your login is not linked to a member record.']);
        }

        try {
            $result = $this->runner->run(
                $currentCycle->getOrFail(),
                $actor->load('user'),
                $request->approver(),
                $request->context(),
            );
        } catch (DomainRuleException $exception) {
            return back()->withErrors(['approver_email' => $exception->getMessage()]);
        }

        $message = "{$result['settled_count']} member(s) settled, "
            .Kwacha::format($result['paid_ngwee']).' paid out.';

        if ($result['skipped_count'] > 0) {
            return back()
                ->with('success', $message)
                ->withErrors([
                    'batch' => $result['skipped_count'].' member(s) could not be settled: '
                        .implode('; ', array_map(
                            fn (array $row): string => "{$row['full_name']} — {$row['reason']}",
                            $result['skipped'],
                        )),
                ]);
        }

        return back()->with('success', $message);
    }

    /** The master payout schedule, with a signature column against every line. */
    public function schedule(CurrentCycle $currentCycle): Response
    {
        $this->authorize('viewAny', Payout::class);

        $cycle = $currentCycle->getOrFail();

        return Pdf::loadView('pdf.payout-schedule', [
            'cycle' => $cycle,
            'schedule' => $this->runner->schedule($cycle),
            'generatedAt' => Carbon::now(),
            'money' => fn (int $ngwee): string => Kwacha::format($ngwee),
        ])
            ->setPaper('a4', 'portrait')
            ->download('unity-payout-schedule-'.Carbon::now()->format('Ymd').'.pdf');
    }
}
