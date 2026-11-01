<?php

use Illuminate\Support\Facades\Route;
use Modules\BLReferenceDetails\Http\Controllers\BLReferenceDetailsController;

Route::middleware([
    'auth:sanctum',
    'user.status',

])->prefix('v1/bl')->group(function () {

 

    Route::post(
        '/reference/details/submit',
        [BLReferenceDetailsController::class, 'finalValidation']
    )
        ->middleware('permission:bl-lead-creation')
        ->name('blreferencedetails.submit');



    Route::match(
        ['post', 'put'],
        '/reference/details/{lead_uuid}/{reference_id}',
        [BLReferenceDetailsController::class, 'update']
    )
        ->middleware('permission:bl-lead-creation')
        ->name('blreferencedetails.update');


    Route::get(
        '/reference/details/{lead_uuid}/{reference_id}',
        [BLReferenceDetailsController::class, 'show']
    )
        ->middleware('permission:bl-lead-creation')
        ->name('blreferencedetails.show');

});
