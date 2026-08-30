<?php

namespace App\Http\Controllers\My;

use App\Domain\Cycles\CurrentCycle;
use App\Domain\Declarations\DeclarationService;
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
 * The member paying the declaration the committee approved, from the screen they
 * declared on.
 *
 * There is nothing to fill in: the amount is the approved one and no other, so the
 * only choice left is the rail. Paying less leaves a variance for the table to chase,
 * and paying more is money the sheet has no row for — neither is the member's to
 * choose here.
 *
 * Two rails, one amount. A prompt goes to the number on the member's record and is
 * approved on the handset. A card opens the provider's own page, which is the only
 * place a card number is ever typed — it never reaches this application, and that is
 * what keeps thirty people in a village banking group out of PCI scope.
 */
class DeclarationPaymentController extends Controller
{
    public function __construct(
        protected CollectionInitiator $initiator,
        protected DeclarationService $declarations,
        protected CurrentCycle $currentCycle,
    ) {}

    public function store(Request $request): RedirectResponse
    {
        $member = $request->user()->member;
        $month = $this->currentCycle->get()?->monthFor(now());

        if ($member === null || $month === null) {
            return back()->with('error', 'Your login is not linked to a member in a running cycle.');
        }

        $declaration = $this->declarations->find($member, $month);

        if ($declaration === null) {
            return back()->with('error', "You have not declared for {$month->label()}, so there is nothing to pay.");
        }

        $byCard = $request->string('channel')->toString() === PaymentChannel::Card->value;

        try {
            $intent = $byCard
                ? $this->initiator->declarationByCard($declaration, $member)
                : $this->initiator->declaration($declaration, $member);
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
                'Check your phone: approve the '.Kwacha::format($intent->amount_ngwee).' prompt to pay your '
                    .$month->label().' declaration.',
            );
    }

    /**
     * What the browser needs to hand the member over to the provider's page.
     *
     * The reference is ours and is what ties the widget's payment back to the
     * declaration; nothing here is trusted on the way back, the verify step asks the
     * provider what actually happened.
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
