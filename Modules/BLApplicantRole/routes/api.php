<?php

use Illuminate\Support\Facades\Route;
use Modules\BLApplicantRole\Http\Controllers\BLApplicantRoleController;

Route::middleware([
    'auth:sanctum',
    'user.status',
   
])->prefix('v1/bl')->group(function () {



    Route::post(
        '/applicant/roles/{lead_uuid}/{applicant_id}',
        [BLApplicantRoleController::class, 'store']
    )
  ->middleware('permission:bl-lead-creation')
    ->name('blapplicantrole.store');


    Route::get(
        '/applicant/roles/{lead_uuid}/{applicant_id}',
        [BLApplicantRoleController::class, 'show']
    )
 ->middleware('permission:bl-lead-creation')
    ->name('blapplicantrole.show');


 


    Route::put(
        '/applicant/roles/{lead_uuid}/{applicant_id}',
        [BLApplicantRoleController::class, 'update']
    )
  ->middleware('permission:bl-lead-creation')
    ->name('blapplicantrole.update');


    Route::delete(
        '/applicant/roles/{lead_uuid}/{applicant_id}',
        [BLApplicantRoleController::class, 'destroy']
    )
->middleware('permission:bl-lead-creation')
    ->name('blapplicantrole.destroy');

});
