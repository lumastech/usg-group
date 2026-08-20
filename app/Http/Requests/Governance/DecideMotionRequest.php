<?php

namespace App\Http\Requests\Governance;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The show of hands.
 *
 * Tallies only — the constitution votes by raised hand, so there is no per-member
 * record to post and none is accepted. Whether the tally carries is worked out
 * server-side against the motion's own basis; nothing about pass or fail comes from
 * the client.
 */
class DecideMotionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('decide', $this->route('motion'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'votes_for' => ['required', 'integer', 'min:0'],
            'votes_against' => ['required', 'integer', 'min:0'],
            'abstentions' => ['required', 'integer', 'min:0'],
        ];
    }
}
