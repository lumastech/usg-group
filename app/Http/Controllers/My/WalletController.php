<?php

namespace App\Http\Controllers\My;

use App\Domain\Cycles\CurrentCycle;
use App\Domain\Payments\CollectionInitiator;
use App\Domain\Payments\PaymentGateway;
use App\Domain\Payments\PaymentIntentService;
use App\Domain\Payments\PaymentPoster;
use App\Domain\Wallets\WalletLedger;
use App\Domain\Wallets\WalletPayments;
use App\Domain\Wallets\WalletRegistry;
use App\Domain\Wallets\WithdrawalService;
use App\Enums\PaymentChannel;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Exceptions\DomainRuleException;
use App\Exceptions\PaymentGatewayException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Wallets\StoreTopUpRequest;
use App\Http\Requests\Wallets\StoreWithdrawalRequest;
use App\Http\Resources\PaymentIntentResource;
use App\Http\Resources\PayoutDestinationResource;
use App\Http\Resources\WalletEntryResource;
use App\Http\Resources\WalletResource;
use App\Models\Member;
use App\Models\PaymentIntent;
use App\Models\PayoutDestination;
use App\Support\Kwacha;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The member's own wallet: what is in it, how it got there, and where it can go.
 *
 * Two rails and nothing else. Money in is a top-up, which no rule refuses. Money out is
 * a withdrawal to a destination the member has already had verified. Everything in
 * between — paying savings, settling a declaration, the K250 — is a movement between
 * two wallets and never touches the provider at all.
 */
class WalletController extends Controller
{
    public function __construct(
        protected WalletRegistry $wallets,
        protected WalletLedger $ledger,
        protected WalletPayments $payments,
        protected WithdrawalService $withdrawals,
        protected CollectionInitiator $initiator,
        protected PaymentIntentService $intents,
        protected PaymentPoster $poster,
        protected CurrentCycle $currentCycle,
    ) {}

    public function index(Request $request, PaymentGateway $gateway): Response
    {
        $member = $request->user()->actingMember();
        $wallet = $this->wallets->forMember($member);

        return Inertia::render('my/Wallet', [
            'wallet' => new WalletResource($wallet),
            'statement' => WalletEntryResource::collection(
                $this->ledger->statement($wallet)->limit(60)->get()
            ),
            'destinations' => PayoutDestinationResource::collection(
                $member->payoutDestinations()->orderByDesc('is_default')->get()
            ),
            'topUps' => PaymentIntentResource::collection($this->topUpsInFlight($member)),
            'widget' => $gateway->widgetConfig(),
            'limits' => [
                'top_up_min_ngwee' => (int) config('wallets.top_ups.min_ngwee', 100),
                'withdrawal_min_ngwee' => (int) config('wallets.withdrawals.min_ngwee', 5_000),
                'withdrawal_fee_ngwee' => (int) config('wallets.withdrawals.fee_estimate_ngwee', 0),
                'available_ngwee' => $this->withdrawals->availableNgwee($wallet),
            ],
            'phone' => $member->phone,
        ]);
    }

    /**
     * Puts money into the wallet.
     *
     * A card is written down and finished in the provider's own widget; a mobile money
     * top-up is pushed to the handset. Neither consults a domain rule, because there is
     * none to consult.
     */
    public function topUp(StoreTopUpRequest $request): RedirectResponse
    {
        $member = $request->user()->actingMember();
        $amount = Kwacha::ofNgwee($request->integer('amount_ngwee'));
        $cycle = $member->cycle;

        try {
            $intent = $request->channel() === PaymentChannel::Card
                ? $this->initiator->topUpByCard($member, $cycle, $amount, $member)
                : $this->initiator->topUp(
                    $member,
                    $cycle,
                    $amount,
                    $member,
                    $request->input('phone'),
                    $request->operator(),
                );
        } catch (DomainRuleException|PaymentGatewayException $exception) {
            return $this->refuse($exception, 'amount_ngwee');
        }

        return back()->with('startedPayment', [
            'id' => $intent->id,
            'reference' => $intent->reference,
            'amount_ngwee' => Kwacha::toNgwee($intent->amount_ngwee),
            'channel' => $intent->channel->value,
            'status' => $intent->status->value,
        ]);
    }

    /**
     * Takes money out, to somewhere the member has already had verified.
     *
     * The wallet is debited as the request goes out, so a member cannot start four
     * withdrawals against one balance. A refusal from the provider puts it straight
     * back; a timeout does not, and says so.
     */
    public function withdraw(StoreWithdrawalRequest $request): RedirectResponse
    {
        $member = $request->user()->actingMember();
        $destination = $this->ownDestination($request, $member);

        try {
            $this->withdrawals->request(
                $member,
                Kwacha::ofNgwee($request->integer('amount_ngwee')),
                $member,
                $destination,
            );
        } catch (DomainRuleException|PaymentGatewayException $exception) {
            return $this->refuse($exception, 'amount_ngwee');
        }

        return back()->with('success', 'Your money is on its way.');
    }

    /**
     * Confirms a top-up by asking the provider, never by believing the browser.
     *
     * The widget's callback runs in the member's own browser and can be forged by
     * anybody who can open the developer tools. What it is good for is telling us when
     * to look.
     */
    public function verify(Request $request, PaymentIntent $intent): RedirectResponse
    {
        abort_unless($intent->member?->user_id === $request->user()->id, 403);

        try {
            $this->intents->refresh($intent);
        } catch (PaymentGatewayException $exception) {
            /* A card the member opened and closed without paying: the provider has
               never heard of the reference, because nothing was ever sent. Released
               rather than left standing, or the top-up sits on the screen forever. Only
               on a definite refusal — a timeout says nothing about the money. */
            if ($intent->status === PaymentStatus::Draft && ! $exception->isRetryable()) {
                $this->intents->abandonDraft($intent, 'Closed without paying.');

                return back()->with('info', 'That top-up was not completed, so nothing was taken. You can try again.');
            }

            return back()->with('error', $exception->reason());
        }

        $this->poster->post($intent->refresh());

        /* Still nothing, long after the prompt went out. Asking the provider first is
           what makes this safe: money that did move has already been credited above,
           so what is released here is an attempt that never happened. */
        if ($this->intents->abandonStalled($intent->refresh())) {
            return back()->with(
                'info',
                'That prompt was never approved, so nothing was taken. You can try again.'
            );
        }

        return back()->with('success', $intent->refresh()->status->memberLabel().'.');
    }

    /**
     * Top-ups the member has started that have not reached the wallet yet.
     *
     * A member who approves a prompt is quicker than everything that credits them: the
     * webhook has to arrive and the poller runs on its own clock. Without the payment
     * on the screen they are left looking at a balance that has not moved, with nothing
     * to press and nothing to explain why — which is exactly the state that makes
     * somebody pay a second time. Every other member payment screen surfaces the
     * payment in flight and offers to check it; this is the wallet's.
     *
     * Posted ones are gone from here because they are in the statement below, and
     * failed or abandoned ones because nothing moved and there is nothing to wait for.
     *
     * @return Collection<int, PaymentIntent>
     */
    protected function topUpsInFlight(Member $member): Collection
    {
        return PaymentIntent::query()
            ->acrossCycles()
            ->where('member_id', $member->id)
            ->where('purpose', PaymentPurpose::WalletTopUp->value)
            ->whereNotIn('status', [
                PaymentStatus::Posted->value,
                PaymentStatus::Failed->value,
                PaymentStatus::Abandoned->value,
            ])
            ->orderByDesc('id')
            ->limit(5)
            ->get();
    }

    /**
     * The destination named in the request, once it is confirmed to be the member's.
     *
     * A 403 rather than a validation error: pointing a withdrawal at somebody else's
     * account is not a typo.
     */
    protected function ownDestination(StoreWithdrawalRequest $request, Member $member): ?PayoutDestination
    {
        if (! $request->filled('payout_destination_id')) {
            return null;
        }

        $destination = PayoutDestination::query()->find($request->integer('payout_destination_id'));

        abort_unless($destination !== null && $destination->member_id === $member->id, 403);

        return $destination;
    }

    /**
     * Turns a refusal into something the member can act on.
     *
     * A request that timed out is not a refusal: money may be moving right now, and an
     * error inviting a retry is how somebody gets charged twice.
     */
    protected function refuse(DomainRuleException|PaymentGatewayException $exception, string $field): RedirectResponse
    {
        if ($exception instanceof PaymentGatewayException && $exception->outcomeUnknown) {
            return back()->with(
                'info',
                'We did not get an answer from the payment provider in time. If a prompt reached your phone, '
                    .'approve it — then check back here in a minute to see where it got to.',
            );
        }

        throw ValidationException::withMessages([
            $field => $exception instanceof PaymentGatewayException
                ? $exception->reason()
                : $exception->getMessage(),
        ]);
    }
}
