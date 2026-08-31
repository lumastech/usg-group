<?php

namespace App\Http\Requests\Members;

use App\Concerns\MemberValidationRules;
use App\Domain\Cycles\CurrentCycle;
use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Correcting a registered member's details.
 *
 * Neither the join date nor the joining fee is editable here: both are settled at
 * registration and changing them would rewrite what tier the member joined under.
 *
 * The email is the address on the member's portal login, not a column on the member
 * record, so it is only accepted for a member who has one — a member without a login
 * is given one through MemberInviter, which sends them an invitation.
 */
class UpdateMemberRequest extends FormRequest
{
    use MemberValidationRules;

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('member'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Member $member */
        $member = $this->route('member');

        return [
            'full_name' => ['required', 'string', 'max:255'],
            'nrc_number' => $this->nrcRules(app(CurrentCycle::class)->getOrFail()->id, $member->id),
            'is_diaspora' => ['boolean'],
            'joining_fee_paid' => ['boolean'],
            'email' => $member->hasLogin()
                ? ['required', 'string', 'email', 'max:255', Rule::unique(User::class, 'email')->ignore($member->user_id)]
                : ['prohibited'],
            ...$this->contactRules(),
            ...$this->nextOfKinRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...$this->memberValidationMessages(),
            'email.prohibited' => 'This member has no portal login yet. Invite them from their profile to give them one.',
            'email.unique' => 'Another login already uses that email address.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_diaspora' => $this->boolean('is_diaspora'),
            'joining_fee_paid' => $this->boolean('joining_fee_paid'),
        ]);
    }
}
