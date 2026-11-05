<?php



namespace Modules\UserManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\UserManagement\Models\OrganizationBranch;

class OrganizationBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $routeBranch = $this->route('branch');

        $branchId = $routeBranch instanceof OrganizationBranch
            ? $routeBranch->getKey()
            : $routeBranch;

        return [
            'organization_id' => [
                'required',
                'exists:organizations,id',
            ],

            'user_type_id' => [
                'required',
                'exists:user_types,id',
            ],

            'parent_branch_id' => [
                'nullable',
                'exists:organization_branches,id',
            ],

            'name' => [
                'required',
                'string',
                'max:255',
            ],

            'branch_code' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique(
                    'organization_branches',
                    'branch_code'
                )->ignore($branchId),
            ],

            'type' => [
                'nullable',
                'string',
                'in:HO,BRANCH,SUB_BRANCH',
            ],

            'address' => [
                'nullable',
                'string',
            ],

            'pincode' => [
                'nullable',
                'string',
                'max:10',
            ],

            'city' => [
                'nullable',
                'string',
                'max:255',
            ],

            'state' => [
                'nullable',
                'string',
                'max:255',
            ],

            'is_active' => [
                'sometimes',
                'boolean',
            ],
        ];
    }
}