<?php

namespace App\Http\Controllers\My;

use App\Domain\Cycles\CurrentCycle;
use App\Domain\Loans\LoanApplicationService;
use App\Domain\Loans\LoanEligibilityService;
use App\Domain\Loans\LoanTenor;
use App\Enums\LoanStatus;
use App\Exceptions\DomainRuleException;
use App\Exceptions\LoanNotEligibleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Loans\StoreLoanRequest;
use App\Http\Resources\LoanResource;
use App\Http\Resources\LoanScheduleItemResource;
use App\Http\Resources\LoanTransactionResource;
use App\Models\Loan;
use App\Support\Kwacha;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A member's own loan.
 *
 * Scoped to the signed-in user's member record, so there is no id in the URL to tamper
 * with. The request form appears only when the member actually qualifies; otherwise
 * they are shown the eligibility service's own reasons, one per failed condition, in
 * the same words the committee sees.
 */
class LoanController extends Controller
{
    public function __construct(protected LoanEligibilityService $eligibility) {}

    public function show(Request $request, CurrentCycle $currentCycle): Response
    {
        $member = $request->user()->member;
        $cycle = $currentCycle->get();

        if ($member === null || $cycle === null) {
            return Inertia::render('my/Loan', [
                'member' => null,
                'loan' => null,
                'schedule' => [],
                'ledger' => [],
                'history' => [],
                'eligibility' => null,
                'rules' => null,
            ]);
        }

        $current = $member->loans()
            ->whereIn('status', array_column([...LoanStatus::blocking(), LoanStatus::Defaulted], 'value'))
            ->with('member')
            ->first();

        /* The ceiling is what the member can ask for today, so quote it back at them. */
        $ceiling = $this->eligibility->ceilingFor($member);

        return Inertia::render('my/Loan', [
            'member' => [
                'id' => $member->id,
                'full_name' => $member->full_name,
                'member_number' => $member->member_number,
            ],
            'loan' => $current === null ? null : new LoanResource($current),
            'schedule' => $current === null
                ? []
                : LoanScheduleItemResource::collection($current->scheduleItems()->with('cycleMonth')->get()),
            'ledger' => $current === null
                ? []
                : LoanTransactionResource::collection($current->transactions()->with('cycleMonth')->get()),
            'history' => LoanResource::collection(
                $member->loans()->whereIn('status', [LoanStatus::Settled->value, LoanStatus::Rejected->value])->get(),
            ),
            'eligibility' => $this->eligibility->check($member, $ceiling, Carbon::today())->toArray(),
            'rules' => [
                'ceiling_ngwee' => Kwacha::toNgwee($ceiling),
                'minimum_ngwee' => LoanTenor::MINIMUM_PRINCIPAL_NGWEE,
                'max_loan_multiple' => $cycle->max_loan_multiple,
                'monthly_interest_bps' => $cycle->monthly_interest_bps,
                'final_repayment_date' => $cycle->final_repayment_date->toDateString(),
            ],
        ]);
    }

    /** A member asking for their own loan. */
    public function store(StoreLoanRequest $request, LoanApplicationService $applications): RedirectResponse
    {
        $member = $request->user()->member;

        if ($member === null || $member->id !== $request->integer('member_id')) {
            return back()->withErrors(['principal_ngwee' => 'You can only request a loan for yourself here.']);
        }

        try {
            $applications->request(
                $member,
                Kwacha::ofNgwee($request->integer('principal_ngwee')),
                $member,
                Carbon::now(),
            );
        } catch (LoanNotEligibleException $exception) {
            return back()->withErrors(['principal_ngwee' => implode(' ', $exception->reasons())]);
        } catch (DomainRuleException $exception) {
            return back()->withErrors(['principal_ngwee' => $exception->getMessage()]);
        }

        return back()->with('success', 'Your loan request has gone to the committee for approval.');
    }
}
