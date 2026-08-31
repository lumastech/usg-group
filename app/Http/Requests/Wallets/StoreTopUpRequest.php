<?php

namespace App\Http\Requests\Wallets;

use App\Domain\Payments\Lenco\LencoOperator;
use App\Enums\MobileMoneyOperator;
use App\Enums\PaymentChannel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A member putting money into their own wallet.
 *
 * The thinnest request in the application, and deliberately so: there is no purpose to
 * pick, no month, no loan and no rule to satisfy. A top-up is always acceptable — the
 * whole point of the wallet is that the group will always take money into a member's
 * own account and decide what it is for afterwards.
 */
class StoreTopUpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->member !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'amount_ngwee' => ['required', 'integer', 'min:'.(int) config('wallets.top_ups.min_ngwee', 100)],
            'channel' => ['required', Rule::in([PaymentChannel::MobileMoney->value, PaymentChannel::Card->value])],
            'phone' => ['nullable', 'string', 'max:24', function (string $attribute, mixed $value, callable $fail): void {
                if (is_string($value) && $value !== '' && ! LencoOperator::isValidPhone($value)) {
                    $fail('That is not a Zambian mobile number.');
                }
            }],
            'operator' => ['nullable', Rule::enum(MobileMoneyOperator::class)],
        ];
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
