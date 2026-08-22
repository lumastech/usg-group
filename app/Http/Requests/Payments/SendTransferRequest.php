<?php

namespace App\Http\Requests\Payments;

use App\Concerns\ResolvesSecondApprover;
use App\Enums\Permission;
use App\Models\Member;
use App\Models\PaymentIntent;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Sending the group's money out.
 *
 * The second signature is optional in the rules and compulsory in the domain: whether
 * it is needed depends on what is being paid and on how new the destination is, and
 * TransferInitiator is the thing that knows. Sending it always would train the
 * committee to type a second password without reading why.
 */
class SendTransferRequest extends FormRequest
{
    use ResolvesSecondApprover;

    public function authorize(): bool
    {
        return $this->user()->can('initiate', PaymentIntent::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'approver_email' => ['nullable', 'email'],
            'approver_password' => ['nullable', 'string', 'required_with:approver_email'],
        ];
    }

    /** The confirming committee member, where one was given. */
    public function approver(Permission $permission = Permission::PayoutsApprove): ?Member
    {
        return $this->filled('approver_email') ? $this->secondApprover($permission) : null;
    }
}
