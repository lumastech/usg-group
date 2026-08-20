<?php

namespace App\Http\Requests\Loans;

use Illuminate\Foundation\Http\FormRequest;

/** Declaring a loan in default. The reason goes on the record and stays there. */
class MarkLoanDefaultRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('markDefault', $this->route('loan'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }
}
