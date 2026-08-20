<?php

namespace App\Http\Requests\Loans;

use App\Concerns\ResolvesSecondApprover;
use App\Enums\Permission;
use App\Models\Member;
use Illuminate\Foundation\Http\FormRequest;

/** The second committee signature the guarantee clause requires before enforcement. */
class SignOffCollateralClaimRequest extends FormRequest
{
    use ResolvesSecondApprover;

    public function authorize(): bool
    {
        return $this->user()->can('signOff', $this->route('claim'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->secondApproverRules();
    }

    public function signer(): Member
    {
        return $this->secondApprover(Permission::LoansApprove);
    }
}
