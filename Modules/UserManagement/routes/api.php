<?php

use Illuminate\Support\Facades\Route;
use Modules\UserManagement\Http\Controllers\UserManagementController;
use Modules\UserManagement\Http\Controllers\UserTypeController;
use Modules\UserManagement\Http\Controllers\VendorTypeController;
use Modules\UserManagement\Http\Controllers\UserRoleController;
use Modules\UserManagement\Http\Controllers\DepartmentController;
use Modules\UserManagement\Http\Controllers\OrganizationController;
use Modules\UserManagement\Http\Controllers\OrganizationBranchController;
use Modules\UserManagement\Http\Controllers\UserPermissionController;



Route::prefix('v1')
    ->middleware(['api', 'auth:sanctum'])
    ->group(function () {

       
        Route::prefix('users')->group(function () {

            Route::get('managers', [UserManagementController::class, 'getManagers']);
            Route::get('type/{type}', [UserManagementController::class, 'getByType']);

            Route::get('departments', [DepartmentController::class, 'index']);
            Route::get('departments/{department}', [DepartmentController::class, 'show']);

            Route::get('roles/{userType}', [UserRoleController::class, 'getRolesByUserType']);

            Route::get('/', [UserManagementController::class, 'index']);
            Route::post('/', [UserManagementController::class, 'store'])->middleware('permission:usermanagement');
            Route::get('/{user}', [UserManagementController::class, 'show']);
            Route::put('/{user}', [UserManagementController::class, 'update'])->middleware('permission:usermanagement');
            Route::patch('/{user}', [UserManagementController::class, 'update'])->middleware('permission:usermanagement');
            Route::delete('/{user}', [UserManagementController::class, 'destroy'])->middleware('permission:usermanagement');

            Route::post('/{user}/roles', [UserManagementController::class, 'assignRoles'])->middleware('permission:usermanagement');
            Route::post('/{user}/products', [UserManagementController::class, 'assignProducts'])->middleware('permission:usermanagement');
            Route::post('/{user}/vendors', [UserManagementController::class, 'assignVendors'])->middleware('permission:usermanagement');
        });

       
        Route::apiResource('user-types', UserTypeController::class);
        Route::apiResource('vendor-types', VendorTypeController::class);

        
        Route::patch(
            'organizations/{uuid}/toggle-status',
            [OrganizationController::class, 'toggleStatus']
        )->name('organizations.toggle-status');

        Route::apiResource('organizations', OrganizationController::class)
            ->parameters(['organizations' => 'uuid'])
            ->names('organizations');

       
        Route::get('permissions', [UserPermissionController::class, 'getAllPermissions']);

        Route::get('users/{user}/permissions', [UserPermissionController::class, 'getUserPermissions']);

        Route::post('users/{user}/permissions/assign', [UserPermissionController::class, 'assignPermissions'])->middleware('permission:usermanagement');

        Route::put('users/{user}/permissions', [UserPermissionController::class, 'updatePermissions'])->middleware('permission:usermanagement');
     
        Route::get(
            'organization/branches/dropdown',
            [OrganizationBranchController::class, 'dropdown']
        );

        Route::patch(
            'organization/branches/{organizationBranch}/toggle-active',
            [OrganizationBranchController::class, 'toggleActive']
        )->middleware('permission:usermanagement');

        Route::apiResource(
            'organization/branches',
            OrganizationBranchController::class
        )->except(['destroy'])->middleware('permission:usermanagement');
    });
