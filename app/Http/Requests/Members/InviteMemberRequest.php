<?php

namespace App\Http\Requests\Members;

use Illuminate\Foundation\Http\FormRequest;

/** Attaching a portal login to a member and inviting them to activate it. */
class InviteMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('invite', $this->route('member'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
        ];
    }
}
