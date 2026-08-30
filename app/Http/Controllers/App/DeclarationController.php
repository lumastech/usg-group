<?php

namespace App\Http\Controllers\App;

use App\Domain\Cycles\CurrentCycle;
use App\Domain\Declarations\DeclarationService;
use App\Domain\Declarations\DeclarationSheet;
use App\Domain\Declarations\DeclarationWindow;
use App\Enums\Permission;
use App\Exceptions\DomainRuleException;
use App\Exceptions\LoanNotEligibleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Declarations\RecordDeclarationRequest;
use App\Models\Cycle;
use App\Models\CycleMonth;
use App\Models\Declaration;
use App\Models\Member;
use App\Support\Kwacha;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The month's declarations, as the committee reads them.
 *
 * The screen is month-stepped rather than paginated by member: what the group asks on
 * the trading day is "who has declared for this month, and who has not", so the
 * missing members are a first-class panel beside the sheet rather than an absence to
 * be inferred from it.
 */
class DeclarationController extends Controller
{
    public function __construct(
        protected DeclarationSheet $sheet,
        protected DeclarationService $declarations,
        protected DeclarationWindow $window,
    ) {}

    public function index(Request $request, CurrentCycle $currentCycle): Response
    {
        $this->authorize('viewAny', Declaration::class);

        $cycle = $currentCycle->get();

        if ($cycle === null) {
            return Inertia::render('app/declarations/Index', [
                'cycle' => null,
                'month' => null,
                'months' => [],
                'sheet' => null,
                'missing' => [],
                'members' => [],
                'rules' => null,
                'abilities' => ['record' => false, 'approve' => false],
            ]);
        }

        $month = $this->resolveMonth($request, $cycle);

        return Inertia::render('app/declarations/Index', [
            'cycle' => ['id' => $cycle->id, 'name' => $cycle->name],
            'month' => $month === null ? null : $this->window->payload($month),
            'months' => $cycle->months->map(fn (CycleMonth $row): array => [
                'id' => $row->id,
                'sequence' => $row->sequence,
                'label' => $row->label(),
                'status' => $row->status,
            ])->all(),
            'sheet' => $month === null ? null : $this->sheet->for($month),
            'missing' => $month === null ? [] : $this->declarations->missingFor($month)
                ->map(fn (Member $member): array => [
                    'id' => $member->id,
                    'member_number' => $member->member_number,
                    'full_name' => $member->full_name,
                    'phone' => $member->phone,
                ])->all(),
            'members' => $cycle->members()->active()->get()->map(fn (Member $member): array => [
                'id' => $member->id,
                'member_number' => $member->member_number,
                'full_name' => $member->full_name,
            ])->all(),
            'rules' => [
                'minimum_ngwee' => Kwacha::toNgwee($cycle->min_savings_ngwee),
                'increment_ngwee' => Kwacha::toNgwee($cycle->savings_increment_ngwee),
                'lockdown_cap_ngwee' => Kwacha::toNgwee($cycle->lockdown_savings_cap_ngwee),
                'is_lockdown' => $month !== null && $cycle->isLockdownMonth($month->sequence),
            ],
            'filters' => ['month' => $month?->sequence],
            'abilities' => [
                'record' => $request->user()->can(Permission::DeclarationsRecord->value),
                /* The "ask". Row-level state (already approved, already processed)
                   lives on the sheet row; the policy re-checks both on the post. */
                'approve' => $request->user()->can(Permission::DeclarationsApprove->value),
            ],
        ]);
    }

    /**
     * Captures a declaration for a member who could not, stamped late when it is.
     *
     * The domain decides whether it is late and whether the amounts are allowed; this
     * only turns a refusal into a field error so the treasurer sees it on the form.
     */
    public function store(RecordDeclarationRequest $request): RedirectResponse
    {
        $actor = $request->user()->member;

        if ($actor === null) {
            return back()->withErrors([
                'member_id' => 'Your login is not linked to a member record, so it cannot be recorded as the actor. Ask an administrator to link it.',
            ]);
        }

        $member = $request->member();
        $month = $request->month();

        try {
            $declaration = $this->declarations->submit(
                $member,
                $month,
                Kwacha::ofNgwee($request->integer('saving_amount_ngwee')),
                Kwacha::ofNgwee($request->integer('loan_repayment_amount_ngwee')),
                Kwacha::ofNgwee($request->integer('loan_requested_amount_ngwee')),
                actor: $actor,
                onBehalf: true,
                note: $request->string('note')->toString() ?: null,
            );
        } catch (LoanNotEligibleException $exception) {
            return back()->withErrors(['loan_requested_amount_ngwee' => implode(' ', $exception->reasons())]);
        } catch (DomainRuleException $exception) {
            return back()->withErrors(['saving_amount_ngwee' => $exception->getMessage()]);
        }

        return back()->with(
            'success',
            "Declaration recorded for {$member->full_name} ({$month->label()})"
                .($declaration->is_late ? ', flagged late.' : '.'),
        );
    }

    /** The month being viewed: the one asked for, else the cycle's current month. */
    protected function resolveMonth(Request $request, Cycle $cycle): ?CycleMonth
    {
        $sequence = $request->integer('month') ?: null;

        return $sequence === null
            ? $cycle->monthFor(now())
            : $cycle->monthAt($sequence);
    }
}
