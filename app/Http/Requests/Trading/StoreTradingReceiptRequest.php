<?php

namespace App\Http\Requests\Trading;

use App\Models\TradingSession;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * Marking money received at the trading table.
 *
 * The datetime is required rather than defaulted to now: the treasurer often enters a
 * row after the fact, and the penalty days hang off when the money actually arrived.
 */
class StoreTradingReceiptRequest extends FormRequest
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
            'actual_in_ngwee' => ['required', 'integer', 'min:0'],
            'received_at' => ['required', 'date'],
        ];
    }

    public function receivedAt(): Carbon
    {
        return Carbon::parse($this->string('received_at')->toString());
    }
}
