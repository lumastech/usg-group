<?php

namespace App\Http\Requests\ShareOut;

use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The workbook upload.
 *
 * Importing writes into every ledger the group keeps, so it is held to the permission
 * that owns the cycle itself rather than to a reporting one.
 */
class UploadWorkbookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permission::CyclesManage->value);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'workbook' => ['required', 'file', 'mimes:xlsx,xls', 'max:10240'],
        ];
    }
}
