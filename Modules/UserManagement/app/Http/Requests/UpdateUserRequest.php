<?php

namespace Modules\UserManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
{
    // ✅ CORRECT WAY - Works with Route Model Binding ({user})
    $userId = $this->route('user')?->id ?? $this->route('id');

    return [
        'user_type_id'        => 'sometimes|exists:user_types,id',
        
        'user_code' => [
            'sometimes',
            'string',
            'max:50',
            Rule::unique('users', 'user_code')->ignore($userId),
        ],
        
        'user_email' => [
            'sometimes',
            'email',
            'max:255',
            Rule::unique('users', 'user_email')->ignore($userId),
        ],
        'user_password' => ['sometimes', 'nullable', 'string', 'max:255'],
        
        'user_name'           => 'sometimes|string|max:255',
        'user_mobile_number'  => 'nullable|string|max:15',
        'user_company_name'   => 'nullable|string',
        'department_id'       => 'nullable|string',
        'user_designation'    => 'nullable|string',
        'branch_id'           => 'nullable|integer|exists:organization_branches,id',
        'branch_ids'          => 'nullable|array',
        'branch_ids.*'        => 'integer|exists:organization_branches,id',
        'address'             => 'nullable|string',
        'pincode'             => 'nullable|string|max:10',
        'district'            => 'nullable|string',
        'city'                => 'nullable|string',
        'state'               => 'nullable|string',
        'is_active'           => 'boolean',
        'parent_id'           => ['nullable', Rule::exists('users', 'id')],
        'report_to'           => ['nullable', Rule::exists('users', 'id')],

        // Relationships
        'role_ids'              => 'nullable|array',
        'role_ids.*'            => 'integer|exists:roles,id',
        'product_ids'           => 'nullable|array',
        'product_ids.*'         => 'integer|exists:products,id',
        'vendors'               => 'nullable|array',
        'vendors.*.vendor_id'   => 'required_with:vendors|integer|exists:users,id',
        'vendors.*.vendor_type_id' => 'required_with:vendors|integer|exists:vendor_types,id',
    ];
}
}