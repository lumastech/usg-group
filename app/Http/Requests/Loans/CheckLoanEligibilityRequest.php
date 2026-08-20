<?php

namespace App\Http\Requests\Loans;

use App\Models\Loan;
use App\Models\Member;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The live eligibility panel in the request wizard.
 *
 * Read-only: it answers what would happen, and writes nothing.
 */
class CheckLoanEligibilityRequest extends FormRequest
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
            'principal_ngwee' => ['required', 'integer', 'min:0'],
            'overriding' => ['nullable', 'boolean'],
        ];
    }

    public function member(): Member
    {
        return Member::findOrFail($this->integer('member_id'));
    }
}
