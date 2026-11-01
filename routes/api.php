<?php

// use App\Http\Controllers\API\LapLeadPermissionController;
// use App\Http\Controllers\API\LapLeadPermissionController; // assume

// Route::middleware('auth:sanctum')->group(function () {
//     // Permissions
//     Route::get('/lead/{leadId}/permissions', [LapLeadPermissionController::class, 'getPermissions']);
//     Route::get('/user/permissions', [LapLeadPermissionController::class, 'globalPermissions']);

//     // CRUD operations with implicit authorization via Policy
//     // Route::apiResource('leads', LapLeadController::class)->except(['create', 'edit']);
//     Route::apiResource('leads', LapLeadController::class)
//     ->except(['create', 'edit'])
//     ->parameters(['leads' => 'lead_id']); 
// });



Route::get('/health', function () {
    return response()->json(['status' => 'ok']);
});