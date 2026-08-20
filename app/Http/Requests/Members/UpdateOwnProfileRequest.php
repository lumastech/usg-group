<?php

namespace App\Http\Requests\Members;

use App\Concerns\MemberValidationRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * What a member may change about their own record.
 *
 * Only how to reach them. Name, NRC, status and joining fee are the committee's to
 * amend, so they are absent here rather than merely disabled in the UI.
 */
class UpdateOwnProfileRequest extends FormRequest
{
    use MemberValidationRules;

    public function authorize(): bool
    {
        return $this->user()->can('updateOwnContactDetails', $this->route('member'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->contactRules();
    }
}
