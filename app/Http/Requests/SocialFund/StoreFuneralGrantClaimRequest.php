<?php

namespace App\Http\Requests\SocialFund;

use App\Enums\FuneralRelationship;
use App\Models\FuneralGrantClaim;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A claim on the K1,000 funeral grant.
 *
 * The relationship rule is the constitution's hard edge: parent, spouse or child, with
 * no override anywhere in the system. Rule::enum against App\Enums\FuneralRelationship
 * is enough because the enum has no fourth case to admit.
 */
class StoreFuneralGrantClaimRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', FuneralGrantClaim::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'member_id' => ['required', Rule::exists('members', 'id')],
            'deceased_name' => ['required', 'string', 'max:120'],
            'relationship' => ['required', Rule::enum(FuneralRelationship::class)],
            'claim_date' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'relationship.*' => 'The funeral grant covers a member\'s parent, spouse or child only.',
        ];
    }

    public function relationship(): FuneralRelationship
    {
        return FuneralRelationship::from($this->string('relationship')->toString());
    }
}
