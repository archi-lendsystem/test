<?php

use Illuminate\Support\Facades\Route;
use Modules\BLWorkDetails\Http\Controllers\BLWorkDetailsController;

Route::middleware([
    'auth:sanctum',
    'user.status',

])->prefix('v1/bl')->group(function () {

  

    Route::post(
        'work/details/{lead_uuid}/{applicant_id}',
        [BLWorkDetailsController::class, 'store']
    )
->middleware('permission:bl-lead-creation')
    ->name('blworkdetails.store');


    Route::get(
        'work/details/{lead_uuid}/{applicant_id}',
        [BLWorkDetailsController::class, 'show']
    )
  ->middleware('permission:bl-work-details.show')
    ->name('blworkdetails.show');


    Route::put(
        'work/details/{lead_uuid}/{applicant_id}',
        [BLWorkDetailsController::class, 'store']
    )
 ->middleware('permission:bl-work-details.update')
    ->name('blworkdetails.update');

});
