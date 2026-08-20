<?php

namespace App\Http\Controllers\App;

use App\Domain\Loans\LoanRepaymentService;
use App\Domain\Savings\MemberBalanceCalculator;
use App\Exceptions\DomainRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Loans\StoreLoanRepaymentRequest;
use App\Models\Loan;
use App\Support\Kwacha;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;

/**
 * Recording money received against a loan.
 *
 * Everything about what the payment clears — the daily late penalty, then interest,
 * then principal — belongs to LoanRepaymentService. This turns its refusal into a
 * validation error on the amount, and refreshes the member's snapshot so the savings
 * matrix agrees immediately.
 */
class LoanRepaymentController extends Controller
{
    public function __invoke(
        StoreLoanRepaymentRequest $request,
        Loan $loan,
        LoanRepaymentService $repayments,
        MemberBalanceCalculator $balances,
    ): RedirectResponse {
        $actor = $request->user()->member;

        if ($actor === null) {
            return back()->withErrors([
                'amount_ngwee' => 'Your login is not linked to a member record, so it cannot be recorded as the actor.',
            ]);
        }

        $receivedOn = Carbon::parse($request->date('received_on'));
        $amount = Kwacha::ofNgwee($request->integer('amount_ngwee'));

        try {
            $repayments->record($loan->load('member', 'cycle'), $amount, $actor, $receivedOn);
        } catch (DomainRuleException $exception) {
            return back()->withErrors(['amount_ngwee' => $exception->getMessage()]);
        }

        $month = $loan->cycle->monthFor($receivedOn);

        if ($month !== null) {
            $balances->rebuildFor($loan->member, $month);
        }

        return back()->with('success', Kwacha::format($amount).' recorded against this loan.');
    }
}
