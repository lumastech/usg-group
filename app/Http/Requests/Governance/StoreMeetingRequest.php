<?php

namespace App\Http\Requests\Governance;

use App\Enums\MeetingType;
use App\Models\Meeting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/** Opening the register for a gathering of the group. */
class StoreMeetingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Meeting::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'meeting_date' => ['required', 'date'],
            'type' => ['required', Rule::enum(MeetingType::class)],
            'subject' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function type(): MeetingType
    {
        return MeetingType::from($this->string('type')->toString());
    }

    public function meetingDate(): Carbon
    {
        return Carbon::parse($this->string('meeting_date')->toString());
    }
}
