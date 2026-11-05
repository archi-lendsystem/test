<?php

namespace Modules\UserManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreUserPermissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'permission_id'    => ['nullable', 'integer', 'exists:permissions,id'],
            'permission_ids'   => ['nullable', 'array'],
            'permission_ids.*' => ['integer', 'exists:permissions,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'permission_id.exists'     => 'The selected permission does not exist.',
            'permission_ids.*.exists'  => 'One or more selected permissions do not exist.',
        ];
    }
}