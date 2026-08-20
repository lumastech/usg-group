<?php

namespace App\Http\Requests\Members;

use App\Enums\ExpulsionGround;
use App\Enums\MemberStatus;
use App\Models\Member;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Recording a change to a member's standing in the group.
 *
 * The allowed target statuses come from the member's current status, so the form
 * cannot offer — or a caller post — a transition the domain would refuse anyway.
 */
class ChangeMemberStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('changeStatus', $this->route('member'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Member $member */
        $member = $this->route('member');

        $allowed = array_map(
            fn (MemberStatus $status): string => $status->value,
            $member->status->allowedTransitions(),
        );

        return [
            'status' => ['required', Rule::in($allowed)],
            'reason' => ['nullable', 'string', 'max:1000'],
            'effective_on' => ['nullable', 'date'],
            'expulsion_ground' => [
                Rule::requiredIf(fn (): bool => $this->input('status') === MemberStatus::Expelled->value),
                'nullable',
                Rule::enum(ExpulsionGround::class),
            ],
            'date_of_death' => [
                Rule::requiredIf(fn (): bool => $this->input('status') === MemberStatus::Deceased->value),
                'nullable',
                'date',
                'before_or_equal:today',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.in' => 'That is not a status this member can be moved to.',
            'expulsion_ground.required' => 'An expulsion must record the ground for it.',
            'date_of_death.required' => 'Recording a death requires the date of death.',
        ];
    }
}
