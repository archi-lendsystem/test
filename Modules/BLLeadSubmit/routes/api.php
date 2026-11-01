<?php

use Illuminate\Support\Facades\Route;
use Modules\BLLeadSubmit\Http\Controllers\BLLeadSubmitController;

Route::middleware([
    'auth:sanctum',
    'user.status',
])->prefix('v1/bl')->group(function () {

    /*
    |--------------------------------------------------------------------------
    | BL Lead Final Submit
    |--------------------------------------------------------------------------
    */

    Route::post(
        '/lead/submit',
        [BLLeadSubmitController::class, 'store']
    )
    ->middleware('permission:bl-lead-creation')
    ->name('blleadsubmit.store');


   

    Route::get(
        '/lead/submit/{lead_uuid}',
        [BLLeadSubmitController::class, 'show']
    )
    ->middleware('permission:bl-lead-creation')
    ->name('blleadsubmit.show');

});

