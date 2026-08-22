<?php

namespace App\Http\Requests\Payments;

use App\Domain\Payments\Lenco\LencoOperator;
use App\Enums\MobileMoneyOperator;
use App\Enums\PaymentPurpose;
use App\Models\PaymentIntent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A committee member pushing a payment prompt to somebody's handset.
 *
 * The amount is validated here only for shape; whether the group may actually accept
 * it is CollectionInitiator's question, asked against the same ledger rules that would
 * refuse the cash.
 */
class RequestPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('initiate', PaymentIntent::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'member_id' => ['required', 'integer', 'exists:members,id'],
            'purpose' => ['required', Rule::in(array_map(
                fn (PaymentPurpose $purpose): string => $purpose->value,
                PaymentPurpose::collections(),
            ))],
            'amount_ngwee' => ['required', 'integer', 'min:100'],
            'cycle_month_id' => ['nullable', 'integer', 'exists:cycle_months,id'],
            'loan_id' => ['nullable', 'integer', 'exists:loans,id'],

            /* The number in front of the treasurer is not always the one on file. */
            'phone' => ['nullable', 'string', 'max:24', function (string $attribute, mixed $value, callable $fail): void {
                if (is_string($value) && $value !== '' && ! LencoOperator::isValidPhone($value)) {
                    $fail('That is not a Zambian mobile number.');
                }
            }],
            'operator' => ['nullable', Rule::enum(MobileMoneyOperator::class)],
        ];
    }

    public function purpose(): PaymentPurpose
    {
        return PaymentPurpose::from($this->string('purpose')->toString());
    }

    public function operator(): ?MobileMoneyOperator
    {
        return $this->filled('operator')
            ? MobileMoneyOperator::from($this->string('operator')->toString())
            : null;
    }

    public function phone(): ?string
    {
        return $this->filled('phone') ? $this->string('phone')->toString() : null;
    }
}
