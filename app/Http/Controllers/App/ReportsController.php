<?php

namespace App\Http\Controllers\App;

use App\Domain\Cycles\CurrentCycle;
use App\Domain\Reporting\MonthlyStatementPack;
use App\Http\Controllers\Controller;
use App\Models\CycleMonth;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One place to find every sheet the group keeps.
 *
 * The hub owns no figures of its own — each card links to the export the owning module
 * already renders, so a report can never drift from the screen it belongs to. What is
 * new here is the monthly pack: the whole month rendered in one go for the mail-out.
 *
 * Cards are permission-aware. A signatory without `loans.view` is not shown a loans
 * export, because the download route would refuse it anyway and offering it would only
 * teach them the portal is broken.
 */
class ReportsController extends Controller
{
    public function __construct(protected MonthlyStatementPack $pack) {}

    public function index(Request $request, CurrentCycle $currentCycle): Response
    {
        $user = $request->user();
        $cycle = $currentCycle->get();

        return Inertia::render('app/Reports', [
            'cycle' => $cycle === null ? null : [
                'id' => $cycle->id,
                'name' => $cycle->name,
                'status' => $cycle->status,
                'status_label' => $cycle->status->label(),
            ],
            'months' => $cycle === null ? [] : $cycle->months()->get()
                ->map(fn (CycleMonth $month): array => [
                    'id' => $month->id,
                    'sequence' => $month->sequence,
                    'label' => $month->label(),
                    'is_current' => $month->month->isSameMonth(Carbon::today()),
                ])->all(),
            'reports' => $this->reports($request),
            'abilities' => [
                'buildPack' => $user->can('reports.view'),
            ],
        ]);
    }

    /**
     * Renders the month's whole pack to disk, ready for the mail-out.
     */
    public function store(Request $request, CurrentCycle $currentCycle): RedirectResponse
    {
        abort_unless($request->user()->can('reports.view'), 403);

        $validated = $request->validate([
            'month' => ['nullable', 'integer', 'min:1', 'max:12'],
        ]);

        $cycle = $currentCycle->getOrFail();
        $month = isset($validated['month'])
            ? $cycle->monthAt($validated['month'])
            : $cycle->monthFor(Carbon::today());

        if ($month === null) {
            return back()->withErrors(['month' => 'That cycle has no such month.']);
        }

        $manifest = $this->pack->build($cycle, $month);

        return back()->with('success', "The {$manifest['month_label']} pack is built: "
            .count($manifest['files']).' documents in '.$manifest['directory'].'.');
    }

    /**
     * The catalogue, each entry carrying the permission its download route enforces.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function reports(Request $request): array
    {
        $user = $request->user();

        $catalogue = [
            [
                'key' => 'savings',
                'title' => 'Savings ledger',
                'description' => 'The SAVINGS sheet: every member, every month, savings and interest side by side.',
                'permission' => 'savings.view',
                'href' => '/app/savings/export',
                'formats' => ['xlsx', 'pdf'],
                'screen' => '/app/savings',
            ],
            [
                'key' => 'loans',
                'title' => 'Loans ledger',
                'description' => 'The LOANS sheet: what each member borrowed, repaid and still owes, month by month.',
                'permission' => 'loans.view',
                'href' => '/app/loans/export',
                'formats' => ['xlsx', 'pdf'],
                'screen' => '/app/loans/matrix',
            ],
            [
                'key' => 'fund',
                'title' => 'Social fund',
                'description' => "Every inflow and outflow of the group's money for bereavements and celebrations.",
                'permission' => 'fund.view',
                'href' => '/app/fund/export',
                'formats' => ['xlsx', 'pdf'],
                'screen' => '/app/fund/ledger',
            ],
            [
                'key' => 'declarations',
                'title' => 'Declarations',
                'description' => 'What the members promised for a month, as read out at the table.',
                'permission' => 'declarations.view',
                'href' => '/app/declarations/export',
                'formats' => ['xlsx', 'pdf'],
                'screen' => '/app/declarations',
                'takes_month' => true,
            ],
            [
                'key' => 'shareout',
                'title' => 'Share-out',
                'description' => 'The last day: total savings, interest, outstanding loan and net payable per member.',
                'permission' => 'payouts.approve',
                'href' => '/app/shareout/export',
                'formats' => ['xlsx', 'pdf'],
                'screen' => '/app/shareout',
            ],
        ];

        return array_values(array_filter(
            $catalogue,
            fn (array $report): bool => $user->can($report['permission']),
        ));
    }
}
