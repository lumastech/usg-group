<?php

namespace App\Http\Controllers\App;

use App\Domain\Loans\LoanApplicationService;
use App\Exceptions\DomainRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Loans\ApproveLoanRequest;
use App\Http\Requests\Loans\RejectLoanRequest;
use App\Models\Loan;
use Illuminate\Http\RedirectResponse;

/**
 * Approving and rejecting loan requests.
 *
 * Approval carries two signatures: the signed-in committee member and a second who
 * typed their own credentials into the dialog. The request has already verified the
 * second pair against the server; the service enforces that they are distinct committee
 * members and that neither is the borrower.
 */
class LoanApprovalController extends Controller
{
    public function __construct(protected LoanApplicationService $applications) {}

    public function store(ApproveLoanRequest $request, Loan $loan): RedirectResponse
    {
        $firstApprover = $request->user()->member;

        if ($firstApprover === null) {
            return back()->withErrors([
                'approver_email' => 'Your login is not linked to a member record, so it cannot stand as an approval.',
            ]);
        }

        try {
            $this->applications->approve($loan, $firstApprover->load('user'), $request->confirmer());
        } catch (DomainRuleException $exception) {
            return back()->withErrors(['approver_email' => $exception->getMessage()]);
        }

        return back()->with('success', "Loan #{$loan->id} approved and added to the disbursement queue.");
    }

    public function destroy(RejectLoanRequest $request, Loan $loan): RedirectResponse
    {
        $actor = $request->user()->member;

        if ($actor === null) {
            return back()->withErrors(['reason' => 'Your login is not linked to a member record.']);
        }

        try {
            $this->applications->reject($loan, $actor, $request->string('reason')->toString());
        } catch (DomainRuleException $exception) {
            return back()->withErrors(['reason' => $exception->getMessage()]);
        }

        return back()->with('success', "Loan #{$loan->id} was turned down.");
    }
}
