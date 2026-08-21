<?php

namespace App\Http\Requests\ShareOut;

use App\Concerns\ResolvesSecondApprover;
use App\Enums\Permission;
use App\Models\Member;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Moving the cycle into share-out.
 *
 * A clean pre-flight checklist needs nothing but `cycles.manage` — the committee is
 * confirming what the checklist already shows. Overriding a dirty one is a different
 * act, so the credentials of a second committee member and a written reason are
 * accepted here and required by CycleCloser; the domain decides which case it is,
 * because the checklist can change between rendering the form and posting it.
 */
class OpenShareOutRequest extends FormRequest
{
    use ResolvesSecondApprover;

    public function authorize(): bool
    {
        return $this->user()->can(Permission::CyclesManage->value);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'override_note' => ['nullable', 'string', 'max:2000'],
            'approver_email' => ['nullable', 'email'],
            'approver_password' => ['nullable', 'string'],
        ];
    }

    /** The confirming committee member, when an override is being attempted. */
    public function approver(): ?Member
    {
        if (! $this->filled('approver_email') || ! $this->filled('approver_password')) {
            return null;
        }

        return $this->secondApprover(Permission::CyclesManage);
    }
}
