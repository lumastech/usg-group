<?php

namespace App\Http\Requests\Loans;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The itemised claim against a defaulting member's household goods.
 *
 * Whether the items add up to what is owed is DefaultWorkflowService's decision, not a
 * validation rule — the message it raises names both figures, which is what the person
 * filling the form needs to see.
 */
class StoreCollateralClaimRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('claimCollateral', $this->route('loan'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.description' => ['required', 'string', 'max:200'],
            'items.*.estimated_value_ngwee' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<int, array{description: string, estimated_value_ngwee: int}>
     */
    public function items(): array
    {
        return array_map(fn (array $item): array => [
            'description' => $item['description'],
            'estimated_value_ngwee' => (int) $item['estimated_value_ngwee'],
        ], $this->input('items'));
    }
}
