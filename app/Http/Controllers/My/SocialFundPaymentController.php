<?php

namespace App\Http\Controllers\My;

use App\Domain\Cycles\CurrentCycle;
use App\Domain\Payments\CollectionInitiator;
use App\Enums\PaymentChannel;
use App\Exceptions\DomainRuleException;
use App\Exceptions\PaymentGatewayException;
use App\Http\Controllers\Controller;
use App\Models\PaymentIntent;
use App\Support\Kwacha;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The member paying their social fund contribution from the screen it is asked for on.
 *
 * There is nothing to fill in. The constitution sets one figure and one occasion, so
 * the amount is the cycle's and never the member's — a part payment is refused by the
 * ledger anyway, and a figure typed on a phone is only a way to be refused after the
 * money has gone.
 *
 * Two rails, one amount. A prompt goes to the number on the member's record and is
 * approved on the handset. A card opens the provider's own page, which is the only
 * place a card number is ever typed — it never reaches this application.
 */
class SocialFundPaymentController extends Controller
{
    public function __construct(
        protected CollectionInitiator $initiator,
        protected CurrentCycle $currentCycle,
    ) {}

    public function store(Request $request): RedirectResponse
    {
        $member = $request->user()->member;
        $cycle = $this->currentCycle->get();

        if ($member === null || $cycle === null) {
            return back()->with('error', 'Your login is not linked to a member in a running cycle.');
        }

        $amount = $cycle->social_fund_contribution_ngwee;
        $month = $cycle->monthFor(now());
        $byCard = $request->string('channel')->toString() === PaymentChannel::Card->value;

        try {
            $intent = $byCard
                ? $this->initiator->socialFundByCard($member, $cycle, $amount, $member, $month)
                : $this->initiator->socialFund($member, $cycle, $amount, $member, $month);
        } catch (PaymentGatewayException $exception) {
            /* The request timed out on its way to the provider, which says nothing about
               whether a prompt reached the handset. The payment is left standing for the
               poller to settle, so the member is told to look at their phone rather than
               to try again — a second prompt against a live one takes the money twice. */
            return $exception->outcomeUnknown
                ? back()->with(
                    'info',
                    'We did not get an answer from the payment provider in time. If a prompt reached your '
                        .'phone, approve it — then use "Check the payment" to confirm it went through.',
                )
                : back()->with('error', $exception->reason());
        } catch (DomainRuleException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return $byCard
            ? back()->with('startedPayment', $this->widgetHandover($intent))
            : back()->with(
                'success',
                'Check your phone: approve the '.Kwacha::format($intent->amount_ngwee)
                    .' prompt to pay your social fund contribution.',
            );
    }

    /**
     * What the browser needs to hand the member over to the provider's page.
     *
     * The reference is ours and is what ties the widget's payment back to the intent;
     * nothing here is trusted on the way back, the verify step asks the provider what
     * actually happened.
     *
     * @return array<string, mixed>
     */
    protected function widgetHandover(PaymentIntent $intent): array
    {
        return [
            'id' => $intent->id,
            'reference' => $intent->reference,
            'amount_ngwee' => Kwacha::toNgwee($intent->amount_ngwee),
            'channel' => $intent->channel->value,
            'status' => $intent->status->value,
        ];
    }
}
