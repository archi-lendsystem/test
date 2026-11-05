<?php

use Illuminate\Support\Facades\Route;
use Modules\BLTeleVerification\Http\Controllers\BLTeleVerificationController;

Route::middleware([
    'auth:sanctum',
    'user.status',
])->prefix('v1/bl')->group(function () {

   

   Route::middleware('permission:bl-tele-verification.show')->group(function () {

        Route::get(
            '/tele-verification/{lead_uuid}',
            [BLTeleVerificationController::class, 'show']
        )->name('blteleverification.show');

        Route::get(
            '/tele-verification/{lead_uuid}/{applicant_sno}',
            [BLTeleVerificationController::class, 'showByApplicant']
        )->name('blteleverification.show-by-applicant');

    });
    Route::post(
        '/tele-verification',
        [BLTeleVerificationController::class, 'store']
    )
        ->middleware('permission:bl-tele-verification.store')
        ->name('blteleverification.store');

});
