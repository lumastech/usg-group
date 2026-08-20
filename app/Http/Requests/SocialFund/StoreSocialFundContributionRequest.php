<?php

namespace App\Http\Requests\SocialFund;

use App\Models\Member;
use App\Models\SocialFundTransaction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A treasurer recording one member's K250 into the Social Fund.
 *
 * Shape only. That the amount is exactly K250 and that the member has not already paid
 * are SocialFundContributions' rules, and stating them again here would let the form
 * and the constitution drift apart.
 */
class StoreSocialFundContributionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', SocialFundTransaction::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'member_id' => ['required', Rule::exists('members', 'id')],
            'amount_ngwee' => ['required', 'integer', 'min:1'],
            'occurred_on' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function member(): Member
    {
        return Member::findOrFail($this->integer('member_id'));
    }
}
