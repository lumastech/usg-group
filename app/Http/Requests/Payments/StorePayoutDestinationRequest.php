<?php

namespace App\Http\Requests\Payments;

use App\Domain\Payments\Lenco\LencoOperator;
use App\Enums\MobileMoneyOperator;
use App\Enums\PayoutDestinationType;
use App\Models\Member;
use App\Models\PayoutDestination;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Saying where a member's money should go.
 *
 * The account is not taken on trust: whatever is posted here is put to the provider,
 * and only what comes back verified is stored. So the rules only have to establish that
 * the right shape of thing was typed for the kind of account chosen.
 */
class StorePayoutDestinationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', [PayoutDestination::class, $this->member()]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(PayoutDestinationType::class)],

            'bank_id' => ['required_if:type,bank_account', 'nullable', 'string', 'max:32'],
            'account_number' => ['required_if:type,bank_account', 'nullable', 'string', 'max:32'],

            'phone' => [
                'required_if:type,mobile_money',
                'nullable',
                'string',
                'max:24',
                function (string $attribute, mixed $value, callable $fail): void {
                    if (is_string($value) && $value !== '' && ! LencoOperator::isValidPhone($value)) {
                        $fail('That is not a Zambian mobile number.');
                    }
                },
            ],
            'operator' => ['nullable', Rule::enum(MobileMoneyOperator::class)],

            'make_default' => ['nullable', 'boolean'],
        ];
    }

    public function type(): PayoutDestinationType
    {
        return PayoutDestinationType::from($this->string('type')->toString());
    }

    public function operator(): ?MobileMoneyOperator
    {
        return $this->filled('operator')
            ? MobileMoneyOperator::from($this->string('operator')->toString())
            : null;
    }

    /** The member the destination belongs to: the route's, or the signed-in one. */
    public function member(): Member
    {
        $member = $this->route('member');

        return $member instanceof Member ? $member : $this->user()->actingMember();
    }
}
