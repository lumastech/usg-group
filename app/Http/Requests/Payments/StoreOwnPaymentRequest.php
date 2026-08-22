<?php

namespace App\Http\Requests\Payments;

use App\Domain\Payments\Lenco\LencoOperator;
use App\Enums\MobileMoneyOperator;
use App\Enums\PaymentChannel;
use App\Enums\PaymentPurpose;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A member paying their own dues.
 *
 * No permission is checked, in keeping with the rest of /my: the route is scoped to the
 * signed-in member's own record, and paying what you owe is not something the
 * constitution restricts. Which member it is for is taken from the session, never from
 * the request.
 */
class StoreOwnPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->member !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'purpose' => ['required', Rule::in(array_map(
                fn (PaymentPurpose $purpose): string => $purpose->value,
                PaymentPurpose::collections(),
            ))],
            'amount_ngwee' => ['required', 'integer', 'min:100'],
            'channel' => ['required', Rule::in([PaymentChannel::MobileMoney->value, PaymentChannel::Card->value])],
            'cycle_month_id' => ['nullable', 'integer', 'exists:cycle_months,id'],
            'loan_id' => ['nullable', 'integer', 'exists:loans,id'],
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

    public function channel(): PaymentChannel
    {
        return PaymentChannel::from($this->string('channel')->toString());
    }

    public function operator(): ?MobileMoneyOperator
    {
        return $this->filled('operator')
            ? MobileMoneyOperator::from($this->string('operator')->toString())
            : null;
    }
}
