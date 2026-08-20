<?php

namespace App\Http\Controllers\App;

use App\Domain\Loans\DefaultWorkflowService;
use App\Exceptions\DomainRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Loans\MarkLoanDefaultRequest;
use App\Models\Loan;
use Illuminate\Http\RedirectResponse;

/** Declaring a loan in default, which opens the collateral claim workflow. */
class LoanDefaultController extends Controller
{
    public function __invoke(
        MarkLoanDefaultRequest $request,
        Loan $loan,
        DefaultWorkflowService $workflow,
    ): RedirectResponse {
        $actor = $request->user()->member;

        if ($actor === null) {
            return back()->withErrors(['reason' => 'Your login is not linked to a member record.']);
        }

        try {
            $workflow->markDefaulted($loan->load('member'), $actor, $request->string('reason')->toString());
        } catch (DomainRuleException $exception) {
            return back()->withErrors(['reason' => $exception->getMessage()]);
        }

        return back()->with(
            'success',
            "Loan #{$loan->id} is in default. Raise the collateral claim to begin recovery.",
        );
    }
}
