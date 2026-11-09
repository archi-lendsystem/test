<?php

use Illuminate\Support\Facades\Route;
use Modules\BLApplicantDetails\Http\Controllers\BLApplicantDetailsController;

/*
| The module RouteServiceProvider already prefixes route names with "api.".
| Therefore the local name must be "bl.applicant.photo.view".
*/
Route::prefix('v1/bl')->group(function () {
    Route::get(
        'applicant/photo/{lead_uuid}/{applicant_id}',
        [BLApplicantDetailsController::class, 'viewPhoto']
    )
        ->middleware('signed:relative')
        ->name('bl.applicant.photo.view');
});

Route::middleware([
    'auth:sanctum',
    'user.status',
])->prefix('v1/bl')->group(function () {
    Route::post(
        'applicant/details/submit',
        [BLApplicantDetailsController::class, 'finalValidation']
    )
        ->middleware('permission:bl-lead-creation')
        ->name('blapplicantdetails.submit');

    Route::get(
        'applicant/details/check-duplicates/{lead_uuid}',
        [BLApplicantDetailsController::class, 'checkInternalDuplicates']
    )
        ->middleware('permission:bl-lead-creation')
        ->name('blapplicantdetails.checkduplicates');

    Route::post(
        'applicant/photo/{lead_uuid}/{applicant_id}',
        [BLApplicantDetailsController::class, 'uploadPhoto']
    )
        ->middleware('permission:bl-lead-creation')
        ->name('bl.applicant.photo.upload');

    Route::get(
        'applicant/details/{lead_uuid}/{applicant_id}',
        [BLApplicantDetailsController::class, 'show']
    )
        ->middleware('permission:bl-lead-creation')
        ->name('blapplicantdetails.show');

    Route::put(
        'applicant/details/{lead_uuid}/{applicant_id}',
        [BLApplicantDetailsController::class, 'update']
    )
        ->middleware('permission:bl-lead-creation')
        ->name('blapplicantdetails.update');
});