<?php

namespace App\Http\Requests\Loans;

use App\Models\Loan;
use App\Models\Member;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Capturing a loan request.
 *
 * Shape only: whether the member may borrow this much, and over how long, belongs to
 * LoanEligibilityService. Repeating those rules here would let the two drift apart, and
 * the wizard already renders the service's own reasons.
 */
class StoreLoanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('requestFor', [Loan::class, $this->member()]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'member_id' => ['required', Rule::exists('members', 'id')],
            'principal_ngwee' => ['required', 'integer', 'min:1'],
            'discretion_note' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function member(): Member
    {
        return Member::findOrFail($this->integer('member_id'));
    }
}
