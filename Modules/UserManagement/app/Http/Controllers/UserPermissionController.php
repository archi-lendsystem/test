<?php

namespace Modules\UserManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\UserManagement\Models\Permission;
use Modules\UserManagement\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Modules\UserManagement\Support\BranchAccess;

class UserPermissionController extends Controller
{
    
    public function getAllPermissions(): JsonResponse
    {
        $permissions = Permission::select('id', 'name', 'slug', 'module', 'description')
            ->orderBy('module')
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'All permissions retrieved successfully.',
            'data'    => $permissions
        ]);
    }

    public function getUserPermissions(Request $request, User $user): JsonResponse
    {
        BranchAccess::ensureCanAccessUser($request->user(), $user);

        $permissions = $user->permissions()
            ->select('permissions.id', 'permissions.name', 'permissions.slug', 'permissions.module', 'permissions.description')
            ->orderBy('permissions.name')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'User assigned permissions retrieved successfully.',
            'data'    => $permissions
        ]);
    }


    public function assignPermissions(Request $request, User $user): JsonResponse
    {
        BranchAccess::ensureCanAccessUser($request->user(), $user);

        $request->validate([
            'permission_ids'   => ['required', 'array', 'min:1'],
            'permission_ids.*' => ['integer', 'exists:permissions,id'],
        ]);

        DB::transaction(function () use ($request, $user) {
            $user->permissions()->syncWithoutDetaching($request->permission_ids);
        });

        
        $assignedPermissions = $user->permissions()
            ->select('permissions.id', 'permissions.name', 'permissions.slug')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Permission(s) assigned successfully.',
            'data'    => $assignedPermissions
        ], 201);
    }

    
    public function updatePermissions(Request $request, User $user): JsonResponse
    {
        BranchAccess::ensureCanAccessUser($request->user(), $user);

        $request->validate([
            'permission_ids'   => ['required', 'array'],
            'permission_ids.*' => ['integer', 'exists:permissions,id'],
        ]);

        DB::transaction(function () use ($request, $user) {
            $user->permissions()->sync($request->permission_ids);
        });

        $permissions = $user->permissions()
            ->select('permissions.id', 'permissions.name', 'permissions.slug')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'User permissions updated successfully.',
            'data'    => $permissions
        ]);
    }
}


// namespace Modules\UserManagement\Http\Controllers;

// use App\Http\Controllers\Controller;
// use Modules\UserManagement\Models\Permission;
// use Modules\UserManagement\Models\User;
// use Illuminate\Http\Request;
// use Illuminate\Http\JsonResponse;
// use Illuminate\Support\Facades\DB;

// class UserPermissionController extends Controller
// {
    
//     public function getAllPermissions(): JsonResponse
//     {
//         $permissions = Permission::select('id', 'name', 'slug', 'module', 'description')
//             ->orderBy('module')
//             ->orderBy('name')
//             ->get();

//         return response()->json([
//             'success' => true,
//             'message' => 'All permissions retrieved successfully.',
//             'data'    => $permissions
//         ]);
//     }

//     public function getUserPermissions(User $user): JsonResponse
//     {
//         $permissions = $user->permissions()
//             ->select('permissions.id', 'permissions.name', 'permissions.slug', 'permissions.module', 'permissions.description')
//             ->orderBy('permissions.name')
//             ->get();

//         return response()->json([
//             'success' => true,
//             'message' => 'User assigned permissions retrieved successfully.',
//             'data'    => $permissions
//         ]);
//     }


//     public function assignPermissions(Request $request, User $user): JsonResponse
//     {
//         $request->validate([
//             'permission_ids'   => ['required', 'array', 'min:1'],
//             'permission_ids.*' => ['integer', 'exists:permissions,id'],
//         ]);

//         DB::transaction(function () use ($request, $user) {
//             $user->permissions()->syncWithoutDetaching($request->permission_ids);
//         });

        
//         $assignedPermissions = $user->permissions()
//             ->select('permissions.id', 'permissions.name', 'permissions.slug')
//             ->get();

//         return response()->json([
//             'success' => true,
//             'message' => 'Permission(s) assigned successfully.',
//             'data'    => $assignedPermissions
//         ], 201);
//     }

    
//     public function updatePermissions(Request $request, User $user): JsonResponse
//     {
//         $request->validate([
//             'permission_ids'   => ['required', 'array'],
//             'permission_ids.*' => ['integer', 'exists:permissions,id'],
//         ]);

//         DB::transaction(function () use ($request, $user) {
//             $user->permissions()->sync($request->permission_ids);
//         });

//         $permissions = $user->permissions()
//             ->select('permissions.id', 'permissions.name', 'permissions.slug')
//             ->get();

//         return response()->json([
//             'success' => true,
//             'message' => 'User permissions updated successfully.',
//             'data'    => $permissions
//         ]);
//     }
// }