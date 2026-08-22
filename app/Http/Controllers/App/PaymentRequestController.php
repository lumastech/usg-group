<?php

namespace App\Http\Controllers\App;

use App\Domain\Cycles\CurrentCycle;
use App\Domain\Payments\CollectionInitiator;
use App\Enums\PaymentPurpose;
use App\Exceptions\DomainRuleException;
use App\Exceptions\PaymentGatewayException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payments\RequestPaymentRequest;
use App\Models\CycleMonth;
use App\Models\Loan;
use App\Models\Member;
use App\Support\Kwacha;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

/**
 * The treasurer asking a member's handset for money.
 *
 * The flow that works for the member who is on a phone call rather than in front of a
 * browser: the treasurer types the amount, the member approves the prompt, and the
 * trading sheet updates itself when the provider confirms it.
 */
class PaymentRequestController extends Controller
{
    public function __construct(
        protected CollectionInitiator $initiator,
        protected CurrentCycle $currentCycle,
    ) {}

    public function __invoke(RequestPaymentRequest $request): RedirectResponse
    {
        $member = Member::query()->acrossCycles()->findOrFail($request->integer('member_id'));
        $actor = $request->user()->member;
        $amount = Kwacha::ofNgwee($request->integer('amount_ngwee'));
        $month = $request->filled('cycle_month_id')
            ? CycleMonth::query()->acrossCycles()->find($request->integer('cycle_month_id'))
            : null;

        try {
            $intent = match ($request->purpose()) {
                PaymentPurpose::SavingsContribution => $this->initiator->savings(
                    $member,
                    $month ?? $this->currentMonth(),
                    $amount,
                    $actor,
                    $request->phone(),
                    $request->operator(),
                ),
                PaymentPurpose::JoiningFee => $this->initiator->joiningFee(
                    $member,
                    $month ?? $this->currentMonth(),
                    $amount,
                    $actor,
                    $request->phone(),
                    $request->operator(),
                ),
                PaymentPurpose::LoanRepayment => $this->initiator->repayment(
                    Loan::query()->acrossCycles()->findOrFail($request->integer('loan_id')),
                    $amount,
                    $actor,
                    $month,
                    $request->phone(),
                    $request->operator(),
                ),
                PaymentPurpose::SocialFundContribution => $this->initiator->socialFund(
                    $member,
                    $member->cycle,
                    $amount,
                    $actor,
                    $month,
                    $request->phone(),
                    $request->operator(),
                ),
                default => throw ValidationException::withMessages([
                    'purpose' => 'That is not something a member can be asked to pay.',
                ]),
            };
        } catch (DomainRuleException|PaymentGatewayException $exception) {
            throw ValidationException::withMessages([
                'amount_ngwee' => $exception instanceof PaymentGatewayException
                    ? $exception->reason()
                    : $exception->getMessage(),
            ]);
        }

        return back()->with(
            'success',
            "{$member->full_name} has been asked to approve ".Kwacha::format($amount)
                ." on their phone (reference {$intent->reference})."
        );
    }

    protected function currentMonth(): CycleMonth
    {
        $cycle = $this->currentCycle->get();
        $month = $cycle?->monthFor(now());

        if ($month === null) {
            throw ValidationException::withMessages([
                'cycle_month_id' => 'Today does not fall inside any month of the current cycle.',
            ]);
        }

        return $month;
    }
}
