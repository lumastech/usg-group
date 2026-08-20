<?php

namespace App\Http\Requests\Payouts;

use App\Concerns\ResolvesSecondApprover;
use App\Enums\Permission;
use App\Models\Member;
use App\Models\Payout;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The last step of the closure wizard, whichever way the breakdown came out.
 *
 * One request settles all four cases and both endings, because the committee performs
 * one act: two of them stand behind a computed position and it is written down. What
 * differs is only what the position was — a payment, or terms for a debt.
 *
 * Nothing about the money is accepted from the client. The amount, the case and the
 * lines are recomputed server-side at the moment of execution; what is posted here is
 * the second signature and the words the committee wrote.
 */
class ExecuteClosureRequest extends FormRequest
{
    use ResolvesSecondApprover;

    public function authorize(): bool
    {
        return $this->user()->can('execute', [Payout::class, $this->route('member')]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /* Required by the domain when a death is settled ahead of share-out. */
            'early_settlement_note' => ['nullable', 'string', 'max:1000'],

            /* Required by the domain when an estate's closure comes out negative. */
            'agreed_terms' => ['nullable', 'string', 'max:2000'],
            'next_of_kin_id' => ['nullable', 'integer', 'exists:next_of_kin,id'],
            'agreed_on' => ['nullable', 'date'],

            'note' => ['nullable', 'string', 'max:1000'],
            ...$this->secondApproverRules(),
        ];
    }

    /**
     * The committee member confirming on the same device.
     *
     * The two halves are deliberately different offices: a treasurer holds
     * `payouts.execute` and hands the money over, and the confirmer holds
     * `payouts.approve` — the chair's side of the table. Neither office can settle a
     * member on its own, which is the whole point of the rule.
     */
    public function approver(): Member
    {
        return $this->secondApprover(Permission::PayoutsApprove);
    }

    /**
     * @return array{
     *     early_settlement_note: string|null,
     *     agreed_terms: string|null,
     *     next_of_kin_id: int|null,
     *     agreed_on: string|null,
     *     note: string|null,
     * }
     */
    public function context(): array
    {
        return [
            'early_settlement_note' => $this->input('early_settlement_note'),
            'agreed_terms' => $this->input('agreed_terms'),
            'next_of_kin_id' => $this->filled('next_of_kin_id') ? $this->integer('next_of_kin_id') : null,
            'agreed_on' => $this->input('agreed_on'),
            'note' => $this->input('note'),
        ];
    }
}
