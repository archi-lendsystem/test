<?php

use Illuminate\Support\Facades\Route;
use Modules\BLOfficeDetails\Http\Controllers\BLOfficeDetailsController;

Route::middleware([
    'auth:sanctum',
    'user.status',
  
])->prefix('v1/bl')->group(function () {

 

    Route::post(
        'office-details/{lead_uuid}/{applicant_id}',
        [BLOfficeDetailsController::class, 'store']
    )
->middleware('permission:bl-lead-creation')
    ->name('blofficedetails.store');


    Route::get(
        'office-details/{lead_uuid}/{applicant_id}',
        [BLOfficeDetailsController::class, 'show']
    )
->middleware('permission:bl-lead-creation')
    ->name('blofficedetails.show');


    Route::put(
        'office-details/{lead_uuid}/{applicant_id}',
        [BLOfficeDetailsController::class, 'update']
    )
      ->middleware('permission:bl-lead-creation')
    ->name('blofficedetails.update');

});
