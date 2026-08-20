<?php

namespace App\Http\Requests\Governance;

use App\Enums\TermEndReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Taking a member out of office.
 *
 * A resignation carries its own arithmetic — a month from the day notice was given —
 * so the notice date is required for that reason and refused for the others, where it
 * would be meaningless. `Removed` is not accepted here at all: a removal is the
 * consequence of a no-confidence motion carrying, and is written by MotionRecorder.
 */
class EndCommitteeTermRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('end', $this->route('term'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'end_reason' => ['required', Rule::in([TermEndReason::TermEnd->value, TermEndReason::Resigned->value])],
            'ended_at' => ['required', 'date'],
            'resignation_notice_date' => [
                Rule::requiredIf(fn (): bool => $this->string('end_reason')->toString() === TermEndReason::Resigned->value),
                'nullable',
                'date',
            ],
            'notice_waiver_note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'end_reason.in' => 'A member is only removed by a no-confidence motion.',
            'resignation_notice_date.required' => 'Record the day notice was given — the month runs from there.',
        ];
    }

    public function reason(): TermEndReason
    {
        return TermEndReason::from($this->string('end_reason')->toString());
    }

    public function endedAt(): Carbon
    {
        return Carbon::parse($this->string('ended_at')->toString());
    }

    public function noticeDate(): ?Carbon
    {
        $date = $this->string('resignation_notice_date')->toString();

        return $date === '' ? null : Carbon::parse($date);
    }
}
