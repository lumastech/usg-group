<?php

namespace App\Http\Controllers\App;

use App\Domain\Payments\TransferInitiator;
use App\Enums\Permission;
use App\Exceptions\DomainRuleException;
use App\Exceptions\PaymentGatewayException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payments\SendTransferRequest;
use App\Models\Payout;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

/**
 * Sending a settled closure to the member.
 *
 * The payout itself was executed, signed and frozen before any of this. If the transfer
 * fails the payout stands — the member's position is settled and their ledgers are
 * closed, which is correct; only the mechanics of handing the money over failed, and it
 * can be retried or paid in cash.
 */
class PayoutTransferController extends Controller
{
    public function __construct(protected TransferInitiator $transfers) {}

    public function __invoke(SendTransferRequest $request, Payout $payout): RedirectResponse
    {
        $this->authorize('pay', $payout);

        try {
            $intent = $this->transfers->payPayout(
                $payout,
                $request->user()->member,
                $request->approver(Permission::PayoutsApprove),
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
            "{$payout->member->full_name}'s share-out is on its way (reference {$intent->reference})."
        );
    }
}
