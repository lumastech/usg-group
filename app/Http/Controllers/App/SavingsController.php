<?php

namespace App\Http\Controllers\App;

use App\Domain\Cycles\CurrentCycle;
use App\Domain\Reporting\SavingsMatrix;
use App\Domain\Savings\SavingsLedger;
use App\Http\Controllers\Controller;
use App\Http\Resources\MemberResource;
use App\Http\Resources\SavingsTransactionResource;
use App\Models\Cycle;
use App\Models\CycleMonth;
use App\Models\Member;
use App\Models\SavingsTransaction;
use App\Support\Kwacha;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The savings ledger, as the workbook lays it out.
 *
 * Reading is open to anyone who may read reports; recording a deposit needs
 * `savings.record`, and the page is told which of the two it is so the entry button
 * appears only for a treasurer.
 */
class SavingsController extends Controller
{
    public function __construct(protected SavingsMatrix $matrix, protected SavingsLedger $ledger) {}

    /** The month matrix: every member, every month, savings beside interest. */
    public function index(Request $request, CurrentCycle $currentCycle): Response
    {
        $this->authorize('viewAny', SavingsTransaction::class);

        $cycle = $currentCycle->get();

        if ($cycle === null) {
            return Inertia::render('app/savings/Index', [
                'matrix' => null,
                'cycle' => null,
                'currentMonth' => null,
                'months' => [],
                'members' => [],
                'rules' => null,
                'abilities' => ['record' => false],
            ]);
        }

        $through = $request->integer('through') ?: null;
        $currentMonth = $this->currentMonth($cycle);

        return Inertia::render('app/savings/Index', [
            'matrix' => $this->matrix->for($cycle, $through),
            'cycle' => ['id' => $cycle->id, 'name' => $cycle->name],
            'currentMonth' => $currentMonth === null ? null : $this->monthPayload($cycle, $currentMonth),
            'months' => $cycle->months->map(fn (CycleMonth $month): array => $this->monthPayload($cycle, $month))->all(),
            'members' => $cycle->members()->get()->map(fn (Member $member): array => [
                'id' => $member->id,
                'member_number' => $member->member_number,
                'full_name' => $member->full_name,
                'status' => $member->status,
                'is_active' => $member->status->value === 'active',
            ])->all(),
            'rules' => [
                'minimum_ngwee' => Kwacha::toNgwee($cycle->min_savings_ngwee),
                'increment_ngwee' => Kwacha::toNgwee($cycle->savings_increment_ngwee),
                'lockdown_cap_ngwee' => Kwacha::toNgwee($cycle->lockdown_savings_cap_ngwee),
                'lockdown_starts_month' => $cycle->loan_lockdown_starts_month,
            ],
            'filters' => ['through' => $through],
            'abilities' => [
                'record' => $request->user()->can('create', SavingsTransaction::class),
            ],
        ]);
    }

    /** One member's savings history, with every entry that produced it. */
    public function show(Request $request, Member $member, CurrentCycle $currentCycle): Response
    {
        $this->authorize('viewAny', SavingsTransaction::class);

        $cycle = $currentCycle->getOrFail();

        return Inertia::render('app/savings/Show', [
            'member' => new MemberResource($member),
            'history' => $this->matrix->forMember($cycle, $member)['months'],
            'transactions' => SavingsTransactionResource::collection(
                SavingsTransaction::query()
                    ->where('member_id', $member->id)
                    ->with('cycleMonth', 'recordedBy')
                    ->latest('occurred_on')
                    ->latest('id')
                    ->paginate(25)
                    ->withQueryString(),
            ),
            'abilities' => [
                'record' => $request->user()->can('recordFor', [SavingsTransaction::class, $member]),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function monthPayload(Cycle $cycle, CycleMonth $month): array
    {
        return [
            'id' => $month->id,
            'sequence' => $month->sequence,
            'label' => $month->label(),
            'lockdown' => $cycle->isLockdownMonth($month->sequence),
        ];
    }

    protected function currentMonth(Cycle $cycle): ?CycleMonth
    {
        return $cycle->months()
            ->whereDate('month', now()->startOfMonth())
            ->first() ?? $cycle->months->last();
    }
}
