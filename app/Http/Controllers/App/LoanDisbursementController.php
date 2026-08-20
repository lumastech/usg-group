<?php

namespace App\Http\Controllers\App;

use App\Domain\Cycles\CurrentCycle;
use App\Domain\Loans\LoanDisbursementQueue;
use App\Exceptions\DomainRuleException;
use App\Exceptions\LoanNotEligibleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Loans\DisburseLoanRequest;
use App\Http\Resources\LoanResource;
use App\Models\Loan;
use App\Support\Kwacha;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The trading-day disbursement queue.
 *
 * Approved loans are paid out in the order they were requested. The screen lists them
 * top-down with the money the fund is committing on the day, and paying somebody out of
 * turn takes a typed reason that stays on their record.
 */
class LoanDisbursementController extends Controller
{
    public function __construct(protected LoanDisbursementQueue $queue) {}

    public function index(Request $request, CurrentCycle $currentCycle): Response
    {
        $this->authorize('viewAny', Loan::class);

        $cycle = $currentCycle->get();
        $month = $cycle?->monthFor(Carbon::today());

        $pending = $month === null ? collect() : $this->queue->pending($month);

        return Inertia::render('app/loans/Queue', [
            'queue' => LoanResource::collection($pending),
            'month' => $month === null ? null : [
                'id' => $month->id,
                'label' => $month->label(),
                'disbursement_on' => $month->disbursement_on->toDateString(),
                'is_trading_day' => $month->disbursement_on->isSameDay(Carbon::today()),
            ],
            'committed_ngwee' => $pending->sum(fn (Loan $loan): int => Kwacha::toNgwee($loan->principal_ngwee)),
        ]);
    }

    public function store(DisburseLoanRequest $request, Loan $loan, CurrentCycle $currentCycle): RedirectResponse
    {
        $actor = $request->user()->member;
        $month = $currentCycle->getOrFail()->monthFor(Carbon::today());

        if ($actor === null || $month === null) {
            return back()->withErrors([
                'out_of_order_reason' => 'This cycle has no month to disburse in, or your login is not linked to a member record.',
            ]);
        }

        try {
            $this->queue->disburse(
                $loan->load('member'),
                $month,
                $actor,
                $request->input('out_of_order_reason'),
            );
        } catch (LoanNotEligibleException $exception) {
            return back()->withErrors(['out_of_order_reason' => implode(' ', $exception->reasons())]);
        } catch (DomainRuleException $exception) {
            return back()->withErrors(['out_of_order_reason' => $exception->getMessage()]);
        }

        return back()->with(
            'success',
            Kwacha::format($loan->principal_ngwee)." disbursed to {$loan->member->full_name}.",
        );
    }
}
