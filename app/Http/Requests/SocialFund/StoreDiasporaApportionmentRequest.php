<?php

namespace App\Http\Requests\SocialFund;

use App\Concerns\ResolvesSecondApprover;
use App\Enums\Permission;
use App\Models\DiasporaApportionment;
use App\Models\Member;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Confirming a diaspora split.
 *
 * Only the total is submitted — who receives a share and how much is the service's
 * arithmetic, so a tampered per-member figure has nowhere to enter the system.
 */
class StoreDiasporaApportionmentRequest extends FormRequest
{
    use ResolvesSecondApprover;

    public function authorize(): bool
    {
        return $this->user()->can('create', DiasporaApportionment::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'total_ngwee' => ['required', 'integer', 'min:1'],
            'declared_on' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:500'],
            ...$this->secondApproverRules(),
        ];
    }

    public function approver(): Member
    {
        return $this->secondApprover(Permission::FundApproveOutflow);
    }
}
