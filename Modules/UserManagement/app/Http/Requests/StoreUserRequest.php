<?php

namespace Modules\UserManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'user_type_id' => 'required|exists:user_types,id',

            // user_code is required ONLY if user_type_id != 2 (Partner)
           'user_code' => [
    'string',
    'nullable',
    Rule::requiredIf(function () {
        return !in_array((int) $this->input('user_type_id'), [2, 3, 4, 5]);
    }),
    Rule::unique('users', 'user_code'),
],

            'user_name'          => 'required|string|max:255',
            'user_email'         => 'required|email|unique:users,user_email',
             'user_password' => ['nullable', 'string', 'max:255'],
            'user_mobile_number' => 'nullable|string|max:15',
            'user_company_name'  => 'nullable|string',
            'department_id'      => 'nullable|string',
            'user_designation'   => 'nullable|string',
            'branch_id'          => 'nullable|integer|exists:organization_branches,id',
            'branch_ids'         => 'nullable|array',
            'branch_ids.*'       => 'integer|exists:organization_branches,id',
            'organization_id'    => 'nullable|exists:organizations,id',
            'address'            => 'nullable|string',
            'pincode'            => 'nullable|string|max:10',
            'district'           => 'nullable|string',
            'city'               => 'nullable|string',
            'state'              => 'nullable|string',
            'is_active'          => 'boolean',
            'parent_id'          => 'nullable|exists:users,id',
            'report_to'          => 'nullable|exists:users,id',
            'created_by'         => 'nullable|exists:users,id',

            // relationships
            'role_ids'               => 'nullable|array',
            'role_ids.*'             => 'exists:roles,id',
            'product_ids'            => 'nullable|array',
            'product_ids.*'          => 'exists:products,id',
            'vendors'                => 'nullable|array',
            'vendors.*.vendor_id'    => 'exists:users,id',
            'vendors.*.vendor_type_id' => 'exists:vendor_types,id',
        ];
    }

    public function messages()
    {
        return [
            'user_email.unique' => 'This email already exists.',
        ];
    }
}