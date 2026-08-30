<?php

namespace App\Http\Controllers\My;

use App\Domain\Cycles\CurrentCycle;
use App\Domain\Declarations\DeclarationService;
use App\Domain\Loans\LoanLedger;
use App\Domain\Payments\CollectionInitiator;
use App\Domain\Payments\PaymentGateway;
use App\Domain\Payments\PaymentIntentService;
use App\Domain\Payments\PaymentPoster;
use App\Domain\Savings\SavingsLedger;
use App\Domain\SocialFund\SocialFundContributions;
use App\Enums\LoanStatus;
use App\Enums\PaymentChannel;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Exceptions\DomainRuleException;
use App\Exceptions\PaymentGatewayException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payments\StoreOwnPaymentRequest;
use App\Http\Resources\PaymentIntentResource;
use App\Models\Cycle;
use App\Models\CycleMonth;
use App\Models\Loan;
use App\Models\Member;
use App\Models\PaymentIntent;
use App\Support\Kwacha;
use Brick\Money\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A member paying what they owe, from their own phone.
 *
 * Two roads out of one screen. A card, or a wallet the member would rather type into
 * the provider's own page, goes through the hosted widget — cards never touch this
 * application, which is what keeps thirty people in a village banking group out of PCI
 * scope. A wallet the member wants pushed to their handset goes down the direct
 * mobile money path instead.
 *
 * Whichever road, the callback from the browser is never believed: the verify step asks
 * the provider what actually happened.
 */
class PaymentController extends Controller
{
    public function __construct(
        protected CollectionInitiator $initiator,
        protected PaymentIntentService $intents,
        protected PaymentPoster $poster,
        protected CurrentCycle $currentCycle,
    ) {}

    public function index(Request $request, PaymentGateway $gateway): Response
    {
        $member = $request->user()->member;
        $cycle = $this->currentCycle->get();
        $month = $cycle?->monthFor(now());

        $payments = PaymentIntent::query()
            ->acrossCycles()
            ->where('member_id', $member?->id)
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return Inertia::render('my/Payments', [
            'payments' => PaymentIntentResource::collection($payments),
            'widget' => $gateway->widgetConfig(),
            'owing' => $member === null || $cycle === null ? null : $this->owing($member, $cycle, $month),
            'month' => $month === null ? null : [
                'id' => $month->id,
                'label' => $month->label(),
            ],
        ]);
    }

    /**
     * Starts a payment.
     *
     * A card payment is only written down here — the member then finishes it in the
     * provider's widget, and the reference we minted is what ties the two together.
     */
    public function store(StoreOwnPaymentRequest $request): RedirectResponse
    {
        $member = $request->user()->actingMember();
        $amount = Kwacha::ofNgwee($request->integer('amount_ngwee'));
        $cycle = $this->currentCycle->get();
        $month = $request->filled('cycle_month_id')
            ? CycleMonth::query()->acrossCycles()->find($request->integer('cycle_month_id'))
            : $cycle?->monthFor(now());

        try {
            $intent = $request->channel() === PaymentChannel::Card
                ? $this->draftForWidget($request, $member, $amount, $month)
                : $this->push($request, $member, $amount, $month);
        } catch (DomainRuleException|PaymentGatewayException $exception) {
            /* A request that timed out is not a refusal: the prompt may be on the
               handset right now, and the intent is left standing for the poller. Saying
               so beats a validation error that invites a second prompt against a live
               one. */
            if ($exception instanceof PaymentGatewayException && $exception->outcomeUnknown) {
                return back()->with(
                    'info',
                    'We did not get an answer from the payment provider in time. If a prompt reached your '
                        .'phone, approve it — then check the payment below to confirm it went through.',
                );
            }

            throw ValidationException::withMessages([
                'amount_ngwee' => $exception instanceof PaymentGatewayException
                    ? $exception->reason()
                    : $exception->getMessage(),
            ]);
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
     * Confirms a payment by asking the provider, never by believing the browser.
     *
     * The widget's success callback runs in the member's own browser and can be forged
     * by anybody who can open the developer tools. What it is good for is telling us
     * when to look.
     */
    public function verify(Request $request, PaymentIntent $intent): RedirectResponse
    {
        abort_unless($intent->member?->user_id === $request->user()->id, 403);

        try {
            $this->intents->refresh($intent);
        } catch (PaymentGatewayException $exception) {
            /* A card the member opened and closed without paying: the provider has
               never heard of the reference because nothing was ever sent. Released
               rather than left standing, or the member could not try again. Only on a
               definite refusal — a timeout or a 500 says nothing about the money. */
            if ($intent->status === PaymentStatus::Draft && ! $exception->isRetryable()) {
                $this->intents->abandonDraft($intent, 'Closed without paying.');

                return back()->with('info', 'That payment was not completed, so nothing was taken. You can try again.');
            }

            return back()->with('error', $exception->reason());
        }

        $this->poster->post($intent->refresh());

        /* Still nothing, long after the prompt went out: nobody is going to approve it
           now, so it is released rather than left blocking the next attempt. Asking the
           provider first is what makes this safe — money that did move has already been
           taken up above. */
        if ($this->intents->abandonStalled($intent->refresh())) {
            return back()->with(
                'info',
                'That prompt was never approved, so nothing was taken. You can try again.'
            );
        }

        return back()->with('success', $intent->refresh()->status->memberLabel().'.');
    }

    /** A payment the member will finish inside the provider's widget. */
    protected function draftForWidget(
        StoreOwnPaymentRequest $request,
        Member $member,
        Money $amount,
        ?CycleMonth $month,
    ): PaymentIntent {
        $this->assertAcceptable($request, $member, $amount, $month);

        return $this->intents->create(
            cycle: $member->cycle,
            purpose: $request->purpose(),
            amountNgwee: Kwacha::toNgwee($amount),
            channel: PaymentChannel::Card,
            member: $member,
            payable: $request->filled('loan_id')
                ? Loan::query()->acrossCycles()->find($request->integer('loan_id'))
                : null,
            month: $month,
            requestedBy: $member,
        );
    }

    /** A prompt pushed straight to the member's handset. */
    protected function push(
        StoreOwnPaymentRequest $request,
        Member $member,
        Money $amount,
        ?CycleMonth $month,
    ): PaymentIntent {
        return match ($request->purpose()) {
            PaymentPurpose::SavingsContribution => $this->initiator->savings(
                $member,
                $this->requireMonth($month),
                $amount,
                $member,
                $request->input('phone'),
                $request->operator(),
            ),
            PaymentPurpose::JoiningFee => $this->initiator->joiningFee(
                $member,
                $this->requireMonth($month),
                $amount,
                $member,
                $request->input('phone'),
                $request->operator(),
            ),
            PaymentPurpose::LoanRepayment => $this->initiator->repayment(
                $this->ownLoan($request, $member),
                $amount,
                $member,
                $month,
                $request->input('phone'),
                $request->operator(),
            ),
            PaymentPurpose::SocialFundContribution => $this->initiator->socialFund(
                $member,
                $member->cycle,
                $amount,
                $member,
                $month,
                $request->input('phone'),
                $request->operator(),
            ),
            default => throw ValidationException::withMessages(['purpose' => 'That is not something you can pay here.']),
        };
    }

    /**
     * The same rules the push path applies, checked before a card payment is drafted.
     *
     * Without this a member could pay K750 by card in a K500-increment month, or pay
     * against a declaration nobody has approved, and only find out it could not be
     * recorded after the money had gone.
     */
    protected function assertAcceptable(
        StoreOwnPaymentRequest $request,
        Member $member,
        Money $amount,
        ?CycleMonth $month,
    ): void {
        match ($request->purpose()) {
            PaymentPurpose::SavingsContribution => $this->assertSavingsPayable($member, $this->requireMonth($month), $amount),
            PaymentPurpose::SocialFundContribution => app(SocialFundContributions::class)
                ->assertPayable($member, $member->cycle, $amount),
            PaymentPurpose::JoiningFee => $member->joining_fee_paid
                ? throw DomainRuleException::make('Your joining fee is already paid.')
                : app(SavingsLedger::class)->assertMemberMaySave($member),
            PaymentPurpose::LoanRepayment => $this->ownLoan($request, $member),
            default => null,
        };
    }

    /**
     * The savings rules and the approval the push path applies, before a card is drafted.
     */
    protected function assertSavingsPayable(Member $member, CycleMonth $month, Money $amount): void
    {
        app(SavingsLedger::class)->assertValidContribution($member, $month, $amount);

        app(DeclarationService::class)->assertPayable($member, $month);
    }

    protected function ownLoan(StoreOwnPaymentRequest $request, Member $member): Loan
    {
        $loan = Loan::query()->acrossCycles()->find($request->integer('loan_id'));

        if ($loan === null || $loan->member_id !== $member->id) {
            throw ValidationException::withMessages(['loan_id' => 'That is not one of your loans.']);
        }

        return $loan;
    }

    protected function requireMonth(?CycleMonth $month): CycleMonth
    {
        if ($month === null) {
            throw ValidationException::withMessages([
                'cycle_month_id' => 'Today does not fall inside any month of the current cycle.',
            ]);
        }

        return $month;
    }

    /**
     * What the member still owes, so the form can fill itself in.
     *
     * @return array<string, mixed>
     */
    protected function owing(Member $member, Cycle $cycle, ?CycleMonth $month): array
    {
        $loan = $member->loans()
            ->whereIn('status', [LoanStatus::Disbursed->value, LoanStatus::Repaying->value, LoanStatus::Defaulted->value])
            ->latest('id')
            ->first();

        return [
            'savings_ngwee' => $month === null
                ? null
                : Kwacha::toNgwee($cycle->min_savings_ngwee),
            'joining_fee_ngwee' => $member->joining_fee_paid ? null : Kwacha::toNgwee($cycle->joining_fee_ngwee),
            'social_fund_ngwee' => app(SocialFundContributions::class)->hasPaid($member)
                ? null
                : Kwacha::toNgwee($cycle->social_fund_contribution_ngwee),
            'loan' => $loan === null ? null : [
                'id' => $loan->id,
                'balance_ngwee' => app(LoanLedger::class)->balanceNgwee($loan),
                'next_due_ngwee' => $loan->nextDueItem()?->outstandingNgwee(),
            ],
        ];
    }
}
