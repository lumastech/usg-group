<?php

namespace App\Http\Requests\Savings;

use App\Models\CycleMonth;
use App\Models\Member;
use App\Models\SavingsTransaction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A treasurer recording one member's monthly savings.
 *
 * Shape only: the amount rules — the minimum, the K500 increment and the lockdown cap
 * — belong to SavingsLedger, which is the only thing that may decide whether money is
 * allowed to move. Duplicating them here would let the two drift apart.
 */
class StoreSavingsDepositRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', SavingsTransaction::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'member_id' => ['required', Rule::exists('members', 'id')],
            'cycle_month_id' => ['required', Rule::exists('cycle_months', 'id')],
            'amount_ngwee' => ['required', 'integer', 'min:1'],
            'declared_amount_ngwee' => ['nullable', 'integer', 'min:0'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function member(): Member
    {
        return Member::findOrFail($this->integer('member_id'));
    }

    public function month(): CycleMonth
    {
        return CycleMonth::findOrFail($this->integer('cycle_month_id'));
    }
}
