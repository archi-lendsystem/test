<?php

use Illuminate\Support\Facades\Route;
use Modules\BLApplicant\Http\Controllers\BLApplicantController;

Route::middleware([
    'auth:sanctum',
    'user.status',

])->prefix('v1/bl')->group(function () {

 

    Route::get(
        '/applicants',
        [BLApplicantController::class, 'index']
    )
    ->middleware('permission:bl-lead-creation')
    ->name('blapplicant.index');

    Route::post(
        '/applicants',
        [BLApplicantController::class, 'store']
    )
     ->middleware('permission:bl-lead-creation')
    ->name('blapplicant.store');

    Route::get(
        '/applicants/{applicant}',
        [BLApplicantController::class, 'show']
    )
    ->middleware('permission:bl-lead-creation')
    ->name('blapplicant.show');

    Route::put(
        '/applicants/{applicant}',
        [BLApplicantController::class, 'update']
    )
    ->middleware('permission:bl-lead-creation')
    ->name('blapplicant.update');

    Route::patch(
        '/applicants/{applicant}',
        [BLApplicantController::class, 'update']
    )
    ->middleware('permission:bl-lead-creation')
    ->name('blapplicant.patch');

    Route::delete(
        'applicants/{lead_uuid}/{applicant_sno}',
        [BLApplicantController::class, 'destroy']
    )
    ->middleware('permission:bl-lead-creation')
    ->name('bl.applicant.destroy');

});
