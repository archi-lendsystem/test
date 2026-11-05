<?php

use Illuminate\Support\Facades\Route;
use Modules\BLApplicantRoleDetails\Http\Controllers\BLApplicantRoleDetailsController;

Route::middleware([
    'auth:sanctum',
    'user.status',
    
])->prefix('v1/bl')->group(function () {

   

    Route::get(
        'applicant-role/details/{lead_uuid}/{applicant_id}',
        [BLApplicantRoleDetailsController::class, 'show']
    )
  ->middleware('permission:bl-lead-creation')
    ->name('blapplicantroledetails.show');


    Route::put(
        'applicant-role/details/{lead_uuid}/{applicant_id}',
        [BLApplicantRoleDetailsController::class, 'update']
    )
 ->middleware('permission:bl-lead-creation')
    ->name('blapplicantroledetails.update');

});
