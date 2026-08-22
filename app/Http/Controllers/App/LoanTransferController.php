<?php

namespace App\Http\Controllers\App;

use App\Domain\Cycles\CurrentCycle;
use App\Domain\Payments\TransferInitiator;
use App\Enums\Permission;
use App\Exceptions\DomainRuleException;
use App\Exceptions\PaymentGatewayException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payments\SendTransferRequest;
use App\Models\Loan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

/**
 * Sending an approved loan to the member's own account.
 *
 * The loan stays Approved until the provider confirms the money left; the queue posts
 * the disbursement then, re-checking eligibility as it always has. A failure here
 * leaves no trace of a loan the member never received.
 */
class LoanTransferController extends Controller
{
    public function __construct(
        protected TransferInitiator $transfers,
        protected CurrentCycle $currentCycle,
    ) {}

    public function __invoke(SendTransferRequest $request, Loan $loan): RedirectResponse
    {
        $this->authorize('disburse', $loan);

        $month = $this->currentCycle->get()?->monthFor(now());

        if ($month === null) {
            throw ValidationException::withMessages([
                'amount' => 'Today does not fall inside any month of the current cycle.',
            ]);
        }

        try {
            $intent = $this->transfers->disburseLoan(
                $loan,
                $month,
                $request->user()->member,
                $request->approver(Permission::LoansApprove),
            );
        } catch (DomainRuleException|PaymentGatewayException $exception) {
            throw ValidationException::withMessages([
                'approver_password' => $exception instanceof PaymentGatewayException
                    ? $exception->reason()
                    : $exception->getMessage(),
            ]);
        }

        return back()->with(
            'success',
            "The money is on its way to {$loan->member->full_name} (reference {$intent->reference})."
        );
    }
}
