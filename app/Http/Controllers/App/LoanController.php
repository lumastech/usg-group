<?php

namespace App\Http\Controllers\App;

use App\Domain\Cycles\CurrentCycle;
use App\Domain\Loans\LoanApplicationService;
use App\Domain\Loans\LoanDisbursementQueue;
use App\Domain\Loans\LoanEligibilityService;
use App\Domain\Loans\LoanTenor;
use App\Enums\LoanStatus;
use App\Enums\MemberStatus;
use App\Exceptions\DomainRuleException;
use App\Exceptions\LoanNotEligibleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Loans\StoreLoanRequest;
use App\Http\Resources\CollateralClaimResource;
use App\Http\Resources\LoanResource;
use App\Http\Resources\LoanScheduleItemResource;
use App\Http\Resources\LoanTransactionResource;
use App\Models\Loan;
use App\Models\Member;
use App\Support\Kwacha;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The loan register.
 *
 * Reading is open to anyone who may read reports — the group watches its own lending —
 * while every write is gated by the permission that owns it, and the row actions on
 * each screen come from the policy's real answers rather than a client-side guess.
 */
class LoanController extends Controller
{
    /** Columns the table may be sorted by, so a query string cannot order by anything. */
    protected const SORTABLE = ['requested_at', 'principal_ngwee', 'current_balance_ngwee', 'status'];

    /** The status tabs, in the order lending actually moves through them. */
    protected const TABS = [
        'requested' => [LoanStatus::Requested],
        'approved' => [LoanStatus::Approved],
        'running' => [LoanStatus::Disbursed, LoanStatus::Repaying],
        'settled' => [LoanStatus::Settled],
        'defaulted' => [LoanStatus::Defaulted],
        'rejected' => [LoanStatus::Rejected],
    ];

    public function __construct(
        protected LoanEligibilityService $eligibility,
        protected LoanDisbursementQueue $queue,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Loan::class);

        $tab = array_key_exists($request->string('tab')->toString(), self::TABS)
            ? $request->string('tab')->toString()
            : 'requested';

        $sort = in_array($request->string('sort')->toString(), self::SORTABLE, true)
            ? $request->string('sort')->toString()
            : 'requested_at';

        $direction = $request->string('direction')->lower()->toString() === 'asc' ? 'asc' : 'desc';

        $loans = Loan::query()
            ->with('member')
            ->whereIn('status', array_column(self::TABS[$tab], 'value'))
            ->when($request->filled('search'), function ($query) use ($request): void {
                $term = '%'.$request->string('search')->trim().'%';

                $query->whereHas('member', fn ($member) => $member->where('full_name', 'like', $term));
            })
            ->orderBy($sort, $direction)
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('app/loans/Index', [
            'loans' => LoanResource::collection($loans),
            'tab' => $tab,
            'tabs' => $this->tabCounts(),
            'filters' => ['search' => $request->string('search')->toString() ?: null],
            'sort' => ['column' => $sort, 'direction' => $direction],
            'abilities' => [
                'create' => $request->user()->can('create', Loan::class),
            ],
        ]);
    }

    /** The request wizard: pick a member, pick an amount, review, submit. */
    public function create(Request $request, CurrentCycle $currentCycle): Response
    {
        $this->authorize('create', Loan::class);

        $cycle = $currentCycle->get();

        return Inertia::render('app/loans/Request', [
            'members' => $cycle === null ? [] : $cycle->members()->where('status', MemberStatus::Active)->get()
                ->map(fn (Member $member): array => [
                    'id' => $member->id,
                    'member_number' => $member->member_number,
                    'full_name' => $member->full_name,
                ])->all(),
            'rules' => $cycle === null ? null : [
                'max_loan_multiple' => $cycle->max_loan_multiple,
                'minimum_ngwee' => LoanTenor::MINIMUM_PRINCIPAL_NGWEE,
                'monthly_interest_bps' => $cycle->monthly_interest_bps,
                'final_repayment_date' => $cycle->final_repayment_date->toDateString(),
                'lockdown_starts_month' => $cycle->loan_lockdown_starts_month,
            ],
            'canOverride' => $request->user()->member?->isCommitteeMember() ?? false,
        ]);
    }

    public function store(StoreLoanRequest $request, LoanApplicationService $applications): RedirectResponse
    {
        $actor = $request->user()->member;

        if ($actor === null) {
            return back()->withErrors([
                'principal_ngwee' => 'Your login is not linked to a member record, so it cannot be recorded as the actor. Ask an administrator to link it.',
            ]);
        }

        try {
            $loan = $applications->request(
                $request->member(),
                Kwacha::ofNgwee($request->integer('principal_ngwee')),
                $actor,
                Carbon::now(),
                $request->input('discretion_note'),
            );
        } catch (LoanNotEligibleException $exception) {
            return back()->withErrors(['principal_ngwee' => implode(' ', $exception->reasons())]);
        } catch (DomainRuleException $exception) {
            return back()->withErrors(['discretion_note' => $exception->getMessage()]);
        }

        return to_route('app.loans.show', $loan)->with(
            'success',
            Kwacha::format($loan->principal_ngwee)." requested for {$loan->member->full_name} over {$loan->tenor_months} month(s).",
        );
    }

    public function show(Request $request, Loan $loan, CurrentCycle $currentCycle): Response
    {
        $this->authorize('view', $loan);

        $loan->loadMissing('member', 'approvedBy', 'secondApprover', 'collateralClaim.preparedBy', 'collateralClaim.secondSigner');

        $month = $currentCycle->get()?->monthFor(Carbon::today());

        return Inertia::render('app/loans/Show', [
            'loan' => new LoanResource($loan),
            'schedule' => LoanScheduleItemResource::collection($loan->scheduleItems()->with('cycleMonth')->get()),
            'ledger' => LoanTransactionResource::collection(
                $loan->transactions()->with('cycleMonth', 'recordedBy')->get(),
            ),
            'claim' => $loan->collateralClaim === null ? null : new CollateralClaimResource($loan->collateralClaim),
            'queuePosition' => $loan->status === LoanStatus::Approved && $month !== null
                ? $this->queue->positionOf($loan, $month)
                : null,
        ]);
    }

    /**
     * How many loans sit under each tab, so the tab bar reads without a second visit.
     *
     * @return array<string, int>
     */
    protected function tabCounts(): array
    {
        $counts = Loan::query()
            ->groupBy('status')
            ->selectRaw('status, COUNT(*) AS total')
            ->pluck('total', 'status');

        return collect(self::TABS)->map(
            fn (array $statuses): int => collect($statuses)->sum(fn (LoanStatus $status): int => (int) ($counts[$status->value] ?? 0)),
        )->all();
    }
}
