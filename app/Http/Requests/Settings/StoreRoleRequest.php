<?php

namespace App\Http\Requests\Settings;

use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shape only: a name, an optional note, and permissions drawn from the enum.
 *
 * Whether the name is free, whether the permissions may be granted together and what
 * `roles.manage` means are RoleManager's answers — this only refuses what could never
 * be a role at all.
 */
class StoreRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permission::RolesManage->value);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60', 'regex:/[a-zA-Z0-9]/'],
            'description' => ['nullable', 'string', 'max:255'],
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', Rule::in(Permission::values())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.regex' => 'A role needs a name made of letters or numbers.',
        ];
    }

    /**
     * The permissions to grant, as plain strings.
     *
     * @return array<int, string>
     */
    public function permissions(): array
    {
        return array_values($this->validated('permissions', []));
    }
}
