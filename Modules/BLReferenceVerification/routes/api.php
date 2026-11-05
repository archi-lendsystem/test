<?php

use Illuminate\Support\Facades\Route;
use Modules\BLReferenceVerification\Http\Controllers\BLReferenceVerificationController;

Route::middleware([
    'auth:sanctum',
    'user.status',
])->prefix('v1/bl')->group(function () {

 

    Route::get(
        '/reference/verifications/{lead_uuid}',
        [BLReferenceVerificationController::class, 'show']
    )
        ->middleware('permission:bl-reference-verification.show')
        ->name('blreferenceverification.show');

    Route::put(
        '/reference/verifications/{lead_uuid}/{reference_detail_id}',
        [BLReferenceVerificationController::class, 'update']
    )
        ->middleware('permission:bl-reference-verification.update')
        ->name('blreferenceverification.update');

});
