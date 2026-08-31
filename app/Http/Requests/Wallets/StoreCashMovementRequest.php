<?php

namespace App\Http\Requests\Wallets;

use App\Concerns\ResolvesSecondApprover;
use App\Enums\Permission;
use App\Models\Member;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A treasurer moving banknotes into or out of a member's wallet at the table.
 *
 * Cash in is the same authority a treasurer already has when recording a cash
 * contribution today. Cash out is stricter than the fund's threshold rule: two
 * signatures whatever the amount, because a provider transfer leaves a record at the
 * provider and a banknote leaves only the wallet entry.
 */
class StoreCashMovementRequest extends FormRequest
{
    use ResolvesSecondApprover;

    public function authorize(): bool
    {
        return $this->user()?->can(Permission::PaymentsInitiate->value) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [
            'member_id' => ['required', 'integer', 'exists:members,id'],
            'amount_ngwee' => ['required', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:255'],
        ];

        return $this->isCashOut() && config('wallets.withdrawals.cash_requires_second_signature', true)
            ? $rules + $this->secondApproverRules()
            : $rules;
    }

    /** Whether this is money leaving the wallet rather than going into it. */
    public function isCashOut(): bool
    {
        return $this->routeIs('*.cash-out');
    }

    public function member(): Member
    {
        return Member::query()->acrossCycles()->findOrFail($this->integer('member_id'));
    }

    /**
     * The confirming committee member, once their credentials have checked out.
     *
     * The confirmer holds `fund.approve-outflow` rather than `payments.initiate`,
     * mirroring the fund's negative-entry rule: the treasury pushes money, and the
     * office that stands behind money leaving confirms it. Requiring the initiating
     * permission on both sides would leave the treasurer and their deputy as the only
     * two people in the group who could ever sign.
     */
    public function confirmer(): ?Member
    {
        if (! $this->isCashOut() || ! config('wallets.withdrawals.cash_requires_second_signature', true)) {
            return null;
        }

        return $this->secondApprover(Permission::FundApproveOutflow);
    }
}
