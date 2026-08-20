<?php

namespace App\Http\Requests\SocialFund;

use App\Concerns\ResolvesSecondApprover;
use App\Enums\Permission;
use App\Models\Member;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The second committee signature a grant needs, whether it is being approved or paid.
 *
 * Both steps take money out of the fund's future or its balance, so both are confirmed
 * on the same device by a second person typing their own credentials.
 */
class ApproveGrantClaimRequest extends FormRequest
{
    use ResolvesSecondApprover;

    public function authorize(): bool
    {
        return $this->user()->can($this->routeIs('*.pay') ? 'pay' : 'approve', $this->route('claim'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'occurred_on' => ['nullable', 'date'],
            ...$this->secondApproverRules(),
        ];
    }

    public function approver(): Member
    {
        return $this->secondApprover(Permission::FundApproveOutflow);
    }
}
