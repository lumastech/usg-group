<?php

namespace App\Http\Requests\Declarations;

use App\Models\CycleMonth;
use App\Models\Declaration;
use App\Models\Member;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The treasurer capturing a declaration on a member's behalf.
 *
 * This is the late-entry path: it is what lets a member who has no phone, or who
 * missed the window, still be on the sheet. The declaration is stamped late by the
 * service, not by anything sent from the client.
 */
class RecordDeclarationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('recordFor', [Declaration::class, $this->member()]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'member_id' => ['required', Rule::exists('members', 'id')],
            'cycle_month_id' => ['required', Rule::exists('cycle_months', 'id')],
            'saving_amount_ngwee' => ['required', 'integer', 'min:0'],
            'loan_repayment_amount_ngwee' => ['required', 'integer', 'min:0'],
            'loan_requested_amount_ngwee' => ['required', 'integer', 'min:0'],
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
