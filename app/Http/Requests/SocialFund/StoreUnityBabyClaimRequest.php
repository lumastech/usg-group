<?php

namespace App\Http\Requests\SocialFund;

use App\Models\UnityBabyClaim;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** A claim on the K500 grant for a child born to a member during the cycle. */
class StoreUnityBabyClaimRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', UnityBabyClaim::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'member_id' => ['required', Rule::exists('members', 'id')],
            'child_name' => ['nullable', 'string', 'max:120'],
            'born_on' => ['required', 'date', 'before_or_equal:today'],
            'claim_date' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
