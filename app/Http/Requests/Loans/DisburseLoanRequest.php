<?php

namespace App\Http\Requests\Loans;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Paying out an approved loan on the trading day.
 *
 * The queue decides whether a reason is actually required — this only carries one if
 * the treasurer typed it, because the order is a fact the server holds, not the client.
 */
class DisburseLoanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('disburse', $this->route('loan'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'out_of_order_reason' => ['nullable', 'string', 'min:5', 'max:500'],
        ];
    }
}
