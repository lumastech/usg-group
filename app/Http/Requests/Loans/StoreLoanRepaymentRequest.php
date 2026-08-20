<?php

namespace App\Http\Requests\Loans;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Recording money received against a loan.
 *
 * The date matters as much as the amount: it decides whether the daily late penalty
 * applies, so it is captured rather than assumed to be today.
 */
class StoreLoanRepaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('recordRepayment', $this->route('loan'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'amount_ngwee' => ['required', 'integer', 'min:1'],
            'received_on' => ['required', 'date'],
        ];
    }
}
