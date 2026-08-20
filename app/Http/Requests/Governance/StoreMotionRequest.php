<?php

namespace App\Http\Requests\Governance;

use App\Enums\MotionType;
use App\Models\Motion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Putting a motion to the group.
 *
 * The threshold basis is deliberately absent: it is fixed by the motion's type, so
 * whoever is minuting cannot pick the easier bar. See App\Enums\MotionType.
 */
class StoreMotionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Motion::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(MotionType::class)],
            'subject' => ['required', 'string', 'max:255'],
            'target_member_id' => [
                Rule::requiredIf(fn (): bool => $this->string('type')->toString() === MotionType::NoConfidence->value),
                'nullable',
                'integer',
                'exists:members,id',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'target_member_id.required' => 'Name the officer the motion concerns.',
        ];
    }

    public function type(): MotionType
    {
        return MotionType::from($this->string('type')->toString());
    }
}
