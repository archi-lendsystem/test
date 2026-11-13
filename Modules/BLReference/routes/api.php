<?php

use Illuminate\Support\Facades\Route;
use Modules\BLReference\Http\Controllers\BLReferenceController;

Route::middleware([
    'auth:sanctum',
    'user.status',
])->prefix('v1/bl')->group(function () {

 

    Route::post(
        '/references/{lead_uuid}',
        [BLReferenceController::class, 'store']
    )
        ->middleware('permission:bl-lead-creation')
        ->name('blreference.store');

    Route::get(
        '/references/{lead_uuid}',
        [BLReferenceController::class, 'index']
    )
        ->middleware('permission:bl-lead-creation')
        ->name('blreference.index');

    Route::get(
        '/references/{lead_uuid}/{reference_id}',
        [BLReferenceController::class, 'show']
    )
        ->middleware('permission:bl-lead-creation')
        ->name('blreference.show');

    Route::delete(
        '/references/{lead_uuid}/{reference_id}',
        [BLReferenceController::class, 'destroy']
    )
        ->middleware('permission:bl-lead-creation')
        ->name('blreference.destroy');

});
