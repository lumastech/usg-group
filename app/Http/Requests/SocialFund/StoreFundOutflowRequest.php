<?php

namespace App\Http\Requests\SocialFund;

use App\Concerns\ResolvesSecondApprover;
use App\Enums\Permission;
use App\Enums\SocialFundTransactionType;
use App\Models\Member;
use App\Models\SocialFundTransaction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A gathering expense or an adjustment posted straight onto the fund's ledger.
 *
 * Anything that reduces the fund carries the second signature, typed into the
 * dual-approval dialog on the same device and verified here rather than trusted.
 */
class StoreFundOutflowRequest extends FormRequest
{
    use ResolvesSecondApprover;

    public function authorize(): bool
    {
        return $this->user()->can('approveOutflow', SocialFundTransaction::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(SocialFundTransactionType::class)],
            'amount_ngwee' => ['required', 'integer'],
            'occurred_on' => ['nullable', 'date'],
            'member_id' => ['nullable', Rule::exists('members', 'id')],
            'note' => ['nullable', 'string', 'max:500'],
            ...$this->secondApproverRules(),
        ];
    }

    public function type(): SocialFundTransactionType
    {
        return SocialFundTransactionType::from($this->string('type')->toString());
    }

    public function subject(): ?Member
    {
        return $this->filled('member_id') ? Member::find($this->integer('member_id')) : null;
    }

    public function approver(): Member
    {
        return $this->secondApprover(Permission::FundApproveOutflow);
    }
}
