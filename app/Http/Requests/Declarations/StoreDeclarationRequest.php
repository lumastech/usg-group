<?php

namespace App\Http\Requests\Declarations;

use App\Models\CycleMonth;
use App\Models\Declaration;
use App\Models\Member;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A member declaring for themselves.
 *
 * Shape only. Whether the window is open, whether the savings step is legal and
 * whether the member may borrow what they are asking for all belong to
 * DeclarationService, which is the single place those answers are decided.
 */
class StoreDeclarationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $member = $this->user()->member;

        return $member !== null && $this->user()->can('submitOwn', [Declaration::class, $member]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'cycle_month_id' => ['required', Rule::exists('cycle_months', 'id')],
            'saving_amount_ngwee' => ['required', 'integer', 'min:0'],
            'loan_repayment_amount_ngwee' => ['required', 'integer', 'min:0'],
            'loan_requested_amount_ngwee' => ['required', 'integer', 'min:0'],
        ];
    }

    public function month(): CycleMonth
    {
        return CycleMonth::findOrFail($this->integer('cycle_month_id'));
    }

    public function member(): Member
    {
        return $this->user()->member;
    }
}
