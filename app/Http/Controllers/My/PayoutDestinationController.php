<?php

namespace App\Http\Controllers\My;

use App\Domain\Payments\PaymentGateway;
use App\Domain\Payments\PayoutDestinationService;
use App\Enums\MobileMoneyOperator;
use App\Enums\PayoutDestinationType;
use App\Exceptions\DomainRuleException;
use App\Exceptions\PaymentGatewayException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payments\StorePayoutDestinationRequest;
use App\Http\Resources\PayoutDestinationResource;
use App\Models\PayoutDestination;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Where the member has asked to be paid.
 *
 * A member may keep a bank account and a mobile money wallet and switch between them.
 * Nothing is saved until the provider has said whose account it is — an unverified
 * destination sitting in the list looking like the others is worse than none at all.
 */
class PayoutDestinationController extends Controller
{
    public function __construct(protected PayoutDestinationService $destinations) {}

    public function index(Request $request, PaymentGateway $gateway): Response
    {
        $member = $request->user()->actingMember();

        $this->authorize('viewAny', [PayoutDestination::class, $member]);

        return Inertia::render('my/Destinations', [
            'destinations' => PayoutDestinationResource::collection(
                $member->payoutDestinations()->orderByDesc('is_default')->orderByDesc('id')->get()
            ),
            'banks' => $this->banks($gateway),
            'operators' => array_map(
                fn (MobileMoneyOperator $operator): array => [
                    'value' => $operator->value,
                    'label' => $operator->label(),
                ],
                MobileMoneyOperator::cases(),
            ),
            'member' => [
                'id' => $member->id,
                'full_name' => $member->full_name,
                'phone' => $member->phone,
            ],
        ]);
    }

    public function store(StorePayoutDestinationRequest $request): RedirectResponse
    {
        $member = $request->member();
        $actor = $request->user()->actingMember();

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
            "Saved. The account is in the name of {$destination->resolved_account_name}."
        );
    }

    public function makeDefault(Request $request, PayoutDestination $destination): RedirectResponse
    {
        $this->authorize('update', $destination);

        $this->destinations->makeDefault($destination, $request->user()->actingMember());

        return back()->with('success', 'Your money will now be sent to '.$destination->label().'.');
    }

    public function destroy(Request $request, PayoutDestination $destination): RedirectResponse
    {
        $this->authorize('delete', $destination);

        $this->destinations->disable($destination, $request->user()->actingMember());

        return back()->with('success', $destination->label().' has been removed.');
    }

    /**
     * The banks the provider can pay into, or nothing when it cannot be reached.
     *
     * A missing list is not an error worth stopping the page for: the member can still
     * manage the wallet half of their details.
     *
     * @return array<int, array{value: string, label: string}>
     */
    protected function banks(PaymentGateway $gateway): array
    {
        try {
            return array_map(
                fn ($bank): array => ['value' => $bank->id, 'label' => $bank->name],
                $gateway->banks(),
            );
        } catch (PaymentGatewayException) {
            return [];
        }
    }
}
