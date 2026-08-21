<?php

namespace App\Http\Requests\ShareOut;

use App\Concerns\ResolvesSecondApprover;
use App\Enums\Permission;
use App\Models\Member;
use App\Models\Payout;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Running the share-out batch.
 *
 * The batch settles many members under one pair of signatures, which is exactly how
 * the group does it in the room: the treasurer and the chair sit down together and
 * work the list. The signatures are still checked per member inside PayoutExecutor —
 * this request only carries them.
 */
class RunShareOutBatchRequest extends FormRequest
{
    use ResolvesSecondApprover;

    public function authorize(): bool
    {
        return $this->user()->can('viewAny', Payout::class)
            && $this->user()->can(Permission::PayoutsExecute->value);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:1000'],
            ...$this->secondApproverRules(),
        ];
    }

    public function approver(): Member
    {
        return $this->secondApprover(Permission::PayoutsApprove);
    }

    /**
     * @return array{note: string|null}
     */
    public function context(): array
    {
        return ['note' => $this->input('note')];
    }
}
