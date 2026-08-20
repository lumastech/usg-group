<?php

namespace App\Http\Requests\Governance;

use App\Enums\CommitteeRole;
use App\Models\CommitteeTerm;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/** Putting a member into office. */
class StoreCommitteeTermRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', CommitteeTerm::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'member_id' => ['required', 'integer', 'exists:members,id'],
            'role' => ['required', Rule::enum(CommitteeRole::class)],
            'started_at' => ['required', 'date'],
        ];
    }

    public function role(): CommitteeRole
    {
        return CommitteeRole::from($this->string('role')->toString());
    }

    public function startedAt(): Carbon
    {
        return Carbon::parse($this->string('started_at')->toString());
    }
}
