<?php

namespace App\Http\Requests\Settings;

use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shape only, as with StoreRoleRequest.
 *
 * `name` is optional here: an office cannot be renamed at all, and RoleManager drops
 * the field for one rather than the form having to know which roles those are.
 */
class UpdateRoleRequest extends FormRequest
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
            'name' => ['nullable', 'string', 'max:60', 'regex:/[a-zA-Z0-9]/'],
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
     * @return array<int, string>
     */
    public function permissions(): array
    {
        return array_values($this->validated('permissions', []));
    }
}
