<?php

namespace App\Http\Requests\Governance;

use App\Models\Amendment;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Proposing a change to the constitution.
 *
 * The six-month spacing rule is not validated here. It belongs to the domain, which
 * refuses the proposal outright — the form only ever opens when the window is, and the
 * screen shows the countdown when it is not.
 */
class StoreAmendmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Amendment::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'meeting_id' => ['required', 'integer', 'exists:meetings,id'],
            'subject' => ['required', 'string', 'max:255'],
            'section_reference' => ['required', 'string', 'max:255'],
            'current_text' => ['required', 'string', 'max:5000'],
            'proposed_text' => ['required', 'string', 'max:5000'],
            'effective_date' => ['required', 'date'],
        ];
    }

    /**
     * @return array{
     *     section_reference: string,
     *     current_text: string,
     *     proposed_text: string,
     *     effective_date: string,
     * }
     */
    public function amendment(): array
    {
        return [
            'section_reference' => $this->string('section_reference')->toString(),
            'current_text' => $this->string('current_text')->toString(),
            'proposed_text' => $this->string('proposed_text')->toString(),
            'effective_date' => $this->string('effective_date')->toString(),
        ];
    }
}
