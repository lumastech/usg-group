<?php

namespace App\Http\Controllers\App;

use App\Domain\Payments\PayoutDestinationService;
use App\Enums\PayoutDestinationType;
use App\Exceptions\DomainRuleException;
use App\Exceptions\PaymentGatewayException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payments\StorePayoutDestinationRequest;
use App\Models\Member;
use App\Models\PayoutDestination;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The committee capturing a payment destination on a member's behalf.
 *
 * For the members who will phone it in rather than open the portal. The same checks
 * apply — the provider is asked whose account it is, the member is told out of band
 * that it changed — and the change starts its own cooling-off period, so a destination
 * captured today still needs a second signature to be paid to today.
 */
class MemberPayoutDestinationController extends Controller
{
    public function __construct(protected PayoutDestinationService $destinations) {}

    public function store(StorePayoutDestinationRequest $request, Member $member): RedirectResponse
    {
        $actor = $request->user()->member;

        try {
            $destination = $request->type() === PayoutDestinationType::BankAccount
                ? $this->destinations->addBankAccount(
                    $member,
                    $request->string('bank_id')->toString(),
                    $request->string('account_number')->toString(),
                    $actor,
                    $request->boolean('make_default', true),
                )
                : $this->destinations->addMobileMoney(
                    $member,
                    $request->string('phone')->toString(),
                    $request->operator(),
                    $actor,
                    $request->boolean('make_default', true),
                );
        } catch (DomainRuleException|PaymentGatewayException $exception) {
            throw ValidationException::withMessages([
                'account_number' => $exception instanceof PaymentGatewayException
                    ? $exception->reason()
                    : $exception->getMessage(),
            ]);
        }

        return back()->with(
            'success',
            "Saved for {$member->full_name}. The account is in the name of {$destination->resolved_account_name}."
        );
    }

    /** A committee member saying, on the record, that a different name is acceptable. */
    public function confirmName(Request $request, PayoutDestination $destination): RedirectResponse
    {
        $this->authorize('confirmName', $destination);

        try {
            $this->destinations->confirmName($destination, $request->user()->member);
        } catch (DomainRuleException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Noted. The name on the account has been confirmed.');
    }
}
