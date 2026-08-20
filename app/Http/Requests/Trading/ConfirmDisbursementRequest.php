<?php

namespace App\Http\Requests\Trading;

use App\Models\TradingSession;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Paying out a queued loan at the table.
 *
 * Only the reason for jumping the queue is captured here; the amount is the loan's
 * principal and comes from the loan, never from the client.
 */
class ConfirmDisbursementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('operate', TradingSession::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'out_of_order_reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
