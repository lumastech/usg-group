<?php

namespace App\Http\Controllers\App;

use App\Domain\Payments\CollectionRequest;
use App\Domain\Payments\Lenco\LencoOperator;
use App\Domain\Payments\PaymentIntentService;
use App\Domain\Payments\PaymentPoster;
use App\Domain\Payments\TransferRequest;
use App\Enums\PaymentStatus;
use App\Exceptions\PaymentGatewayException;
use App\Http\Controllers\Controller;
use App\Models\PaymentIntent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The three things a committee member can do to a payment already on the record.
 *
 * None of them invent money: refreshing asks the provider what it says, retrying
 * starts a fresh attempt with a fresh reference, and resolving decides what happens to
 * money the ledgers refused.
 */
class PaymentActionController extends Controller
{
    public function __construct(
        protected PaymentIntentService $intents,
        protected PaymentPoster $poster,
    ) {}

    /** Ask the provider again, and post anything that has since settled. */
    public function refresh(PaymentIntent $intent): RedirectResponse
    {
        $this->authorize('refresh', $intent);

        try {
            $this->intents->refresh($intent);
        } catch (PaymentGatewayException $exception) {
            return back()->with('error', $exception->reason());
        }

        $this->poster->post($intent->refresh());

        return back()->with('success', "Payment {$intent->reference} is ".mb_strtolower($intent->refresh()->status->label()).'.');
    }

    /**
     * A fresh attempt at the same thing.
     *
     * A new intent with a new reference — the provider refuses a reference it has seen,
     * and two attempts collapsed into one row is a history the group cannot read back.
     */
    public function retry(Request $request, PaymentIntent $intent): RedirectResponse
    {
        $this->authorize('retry', $intent);

        $actor = $request->user()->member;
        $retry = $this->intents->retry($intent, $actor);

        try {
            $sent = $intent->isCollection()
                ? $this->intents->sendCollection($retry, CollectionRequest::from(
                    $retry,
                    $retry->member?->phone,
                    $retry->member?->phone === null ? null : LencoOperator::forPhone($retry->member->phone),
                ))
                : $this->intents->sendTransfer($retry, TransferRequest::from(
                    $retry,
                    $retry->destination,
                    $retry->purpose->label(),
                ));
        } catch (PaymentGatewayException $exception) {
            return back()->with('error', $exception->reason());
        }

        return back()->with('success', "A new attempt has gone out as {$sent->reference}.");
    }

    /**
     * What happens to money the ledgers would not take.
     *
     * Two answers, both a person's to give: try posting it again now that whatever
     * blocked it has been dealt with, or set it aside as handled outside the system.
     */
    public function resolve(Request $request, PaymentIntent $intent): RedirectResponse
    {
        $this->authorize('resolve', $intent);

        $validated = $request->validate([
            'action' => ['required', 'in:post,set-aside'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($validated['action'] === 'post') {
            $intent->forceFill(['status' => PaymentStatus::Settled])->save();

            return $this->poster->post($intent->refresh())
                ? back()->with('success', "Payment {$intent->reference} has been recorded.")
                : back()->with('error', $intent->refresh()->status_reason ?? 'That payment still cannot be recorded.');
        }

        $intent->forceFill([
            'status' => PaymentStatus::Abandoned,
            'status_reason' => $validated['note'] ?? 'Set aside by the committee; handled outside the system.',
        ])->save();

        return back()->with('success', "Payment {$intent->reference} has been set aside.");
    }
}
