<?php

namespace App\Http\Requests\Members;

use App\Concerns\MemberValidationRules;
use App\Domain\Cycles\CurrentCycle;
use App\Models\Member;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Correcting a registered member's details.
 *
 * Neither the join date nor the joining fee is editable here: both are settled at
 * registration and changing them would rewrite what tier the member joined under.
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
            ...$this->contactRules(),
            ...$this->nextOfKinRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->memberValidationMessages();
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_diaspora' => $this->boolean('is_diaspora'),
            'joining_fee_paid' => $this->boolean('joining_fee_paid'),
        ]);
    }
}
