<?php

use Illuminate\Support\Facades\Route;
use Modules\BLBusinessDetails\Http\Controllers\BLBusinessDetailsController;

Route::middleware([
    'auth:sanctum',
    'user.status',

])->prefix('v1/bl')->group(function () {

 

    Route::post(
        'business-details/{lead_uuid}/{applicant_id}',
        [BLBusinessDetailsController::class, 'store']
    )
        ->middleware('permission:bl-lead-creation')
        ->name('blbusinessdetails.store');

    Route::get(
        'business-details/{lead_uuid}/{applicant_id}',
        [BLBusinessDetailsController::class, 'show']
    )
        ->middleware('permission:bl-lead-creation')
        ->name('blbusinessdetails.show');

});
