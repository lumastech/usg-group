<?php

namespace App\Http\Controllers\App;

use App\Domain\Cycles\CurrentCycle;
use App\Domain\Payments\ShareOutPaymentRunner;
use App\Enums\Permission;
use App\Exceptions\DomainRuleException;
use App\Exceptions\PaymentGatewayException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payments\SendTransferRequest;
use App\Models\Payout;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Paying the whole share-out schedule out through the gateway.
 *
 * The settling already happened on the batch screen. This is the money leaving, and the
 * one thing it will not do is start a run it cannot finish: the group's balance is
 * checked against the whole schedule first, because half a room paid and no record of
 * who is next is the worst place this system could leave anybody.
 */
class ShareOutPaymentController extends Controller
{
    public function __construct(
        protected ShareOutPaymentRunner $runner,
        protected CurrentCycle $currentCycle,
    ) {}

    public function show(): Response
    {
        $this->authorize('viewAny', Payout::class);

        $cycle = $this->currentCycle->get();

        return Inertia::render('app/shareout/Payments', [
            'cycle' => $cycle === null ? null : [
                'id' => $cycle->id,
                'name' => $cycle->name,
                'status' => $cycle->status,
                'status_label' => $cycle->status->label(),
            ],
            'preview' => $cycle === null ? null : $this->runner->preview($cycle),
        ]);
    }

    public function store(SendTransferRequest $request): RedirectResponse
    {
        $cycle = $this->currentCycle->get();

        if ($cycle === null) {
            throw ValidationException::withMessages(['approver_email' => 'There is no active cycle to pay out.']);
        }

        $this->authorize('payAny', Payout::class);

        $approver = $request->approver(Permission::PayoutsApprove);

        if ($approver === null) {
            throw ValidationException::withMessages([
                'approver_email' => 'Paying the schedule out needs a second committee member to confirm it.',
            ]);
        }

        try {
            $result = $this->runner->run($cycle, $request->user()->actingMember(), $approver);
        } catch (DomainRuleException|PaymentGatewayException $exception) {
            throw ValidationException::withMessages([
                'approver_password' => $exception instanceof PaymentGatewayException
                    ? $exception->reason()
                    : $exception->getMessage(),
            ]);
        }

        return back()->with('success', sprintf(
            '%d transfer(s) sent, %d could not be sent, %d to pay by hand.',
            $result['sent_count'],
            $result['failed_count'],
            count($result['by_hand']),
        ))->with('shareOutPaymentResult', $result);
    }
}
