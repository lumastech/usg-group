<?php

namespace App\Http\Requests\Wallets;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A member taking their own money out.
 *
 * The destination is validated as belonging to the member in the controller rather than
 * here, so the refusal reads as an authorisation failure and not as a bad field.
 * Redirecting a payout is the highest-value attack in the system.
 */
class StoreWithdrawalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->member !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'amount_ngwee' => [
                'required',
                'integer',
                'min:'.(int) config('wallets.withdrawals.min_ngwee', 5_000),
            ],
            'payout_destination_id' => ['nullable', 'integer', 'exists:payout_destinations,id'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'amount_ngwee.min' => 'A withdrawal must be at least K'
                .number_format(((int) config('wallets.withdrawals.min_ngwee', 5_000)) / 100, 2)
                .', so the fee is never a large part of what is sent.',
        ];
    }
}
