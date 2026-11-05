<?php

namespace Modules\UserManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\UserManagement\Models\Role;
use Modules\UserManagement\Support\BranchAccess;
use Modules\UserManagement\Transformers\RoleResource;

class UserRoleController extends Controller
{
    use ApiResponse;

    public function index(): JsonResponse
    {
        $roles = Role::where('status', true)
            ->orderBy('display_name')
            ->get();

        return $this->success(RoleResource::collection($roles));
    }

     public function getRolesByUserType(Request $request, string $userType): JsonResponse
{
    switch ($userType) {

        case 'employee':
            $roles = Role::where('status', true)
                ->whereNotIn('name', [
                    'partner_administrative',
                    'partner_sub_user',
                    'legal_administrative',
                    'legal_sub_user',
                    'technical_administrative',
                    'technical_sub_user',
                    'collection_administrative',
                    'collection_sub_user',
                ])
                ->orderBy('display_name')
                ->get();
            break;

        case 'partner':
            $roles = Role::where('status', true)
                ->whereIn('name', [
                    'partner_administrative',
                    'partner_sub_user',
                ])
                ->orderBy('display_name')
                ->get();
            break;

        case 'vendor':
            $roles = Role::where('status', true)
                ->whereIn('name', [
                    'administration',
                    'sub_user',
                ])
                ->orderBy('display_name')
                ->get();
            break;

        case 'technical':
            $roles = Role::where('status', true)
                ->whereIn('name', [
                    'technical_administrative',
                    'technical_sub_user',
                ])
                ->orderBy('display_name')
                ->get();
            break;

        case 'legal':
            $roles = Role::where('status', true)
                ->whereIn('name', [
                    'legal_administrative',
                    'legal_sub_user',
                ])
                ->orderBy('display_name')
                ->get();
            break;

        case 'pd_vendor':
            $roles = Role::where('status', true)
                ->whereIn('name', [
                    'administration',
                    'sub_user',
                ])
                ->orderBy('display_name')
                ->get();
            break;

        case 'collection':
            $roles = Role::where('status', true)
                ->whereIn('name', [
                    'collection_administrative',
                    'collection_sub_user',
                ])
                ->orderBy('display_name')
                ->get();
            break;

        case 'lender':
            $roles = Role::where('status', true)
                ->whereIn('name', [
                    'administration',
                    'sub_user',
                ])
                ->orderBy('display_name')
                ->get();
            break;

        default:
            return $this->error('Invalid user type.', [], 422);
    }

    if (BranchAccess::isBranchScopedAdministrator($request->user())) {
        $roles = $roles->reject(
            fn (Role $role) => $role->code === 'SUPER_ADMINISTRATIVE'
        )->values();
    }

    return $this->success(RoleResource::collection($roles));
}
}


// namespace Modules\UserManagement\Http\Controllers;

// use App\Http\Controllers\Controller;
// use App\Traits\ApiResponse;
// use Illuminate\Http\JsonResponse;
// use Modules\UserManagement\Models\Role;
// use Modules\UserManagement\Transformers\RoleResource;

// class UserRoleController extends Controller
// {
//     use ApiResponse;

//     public function index(): JsonResponse
//     {
//         $roles = Role::where('status', true)
//             ->orderBy('display_name')
//             ->get();

//         return $this->success(RoleResource::collection($roles));
//     }

//      public function getRolesByUserType(string $userType): JsonResponse
// {
//     switch ($userType) {

//         case 'employee':
//             $roles = Role::where('status', true)
//                 ->whereNotIn('name', [
//                     'partner_administrative',
//                     'partner_sub_user',
//                     'legal_administrative',
//                     'legal_sub_user',
//                     'technical_administrative',
//                     'technical_sub_user',
//                     'collection_administrative',
//                     'collection_sub_user',
//                 ])
//                 ->orderBy('display_name')
//                 ->get();
//             break;

//         case 'partner':
//             $roles = Role::where('status', true)
//                 ->whereIn('name', [
//                     'partner_administrative',
//                     'partner_sub_user',
//                 ])
//                 ->orderBy('display_name')
//                 ->get();
//             break;

//         case 'vendor':
//             $roles = Role::where('status', true)
//                 ->whereIn('name', [
//                     'administration',
//                     'sub_user',
//                 ])
//                 ->orderBy('display_name')
//                 ->get();
//             break;

//         case 'technical':
//             $roles = Role::where('status', true)
//                 ->whereIn('name', [
//                     'technical_administrative',
//                     'technical_sub_user',
//                 ])
//                 ->orderBy('display_name')
//                 ->get();
//             break;

//         case 'legal':
//             $roles = Role::where('status', true)
//                 ->whereIn('name', [
//                     'legal_administrative',
//                     'legal_sub_user',
//                 ])
//                 ->orderBy('display_name')
//                 ->get();
//             break;

//         case 'pd_vendor':
//             $roles = Role::where('status', true)
//                 ->whereIn('name', [
//                     'administration',
//                     'sub_user',
//                 ])
//                 ->orderBy('display_name')
//                 ->get();
//             break;

//         case 'collection':
//             $roles = Role::where('status', true)
//                 ->whereIn('name', [
//                     'collection_administrative',
//                     'collection_sub_user',
//                 ])
//                 ->orderBy('display_name')
//                 ->get();
//             break;

//         case 'lender':
//             $roles = Role::where('status', true)
//                 ->whereIn('name', [
//                     'administration',
//                     'sub_user',
//                 ])
//                 ->orderBy('display_name')
//                 ->get();
//             break;

//         default:
//             return $this->error('Invalid user type.', [], 422);
//     }

//     return $this->success(RoleResource::collection($roles));
// }
// }