<?php

use Illuminate\Support\Facades\Route;
use Modules\BLCaseDiscusion\Http\Controllers\BLCaseDiscusionController;

Route::middleware([
    'auth:sanctum',
    'user.status',
])->prefix('v1/bl')->group(function () {

   

    Route::prefix('/case-discussion')->group(function () {

      

        Route::post(
            '/',
            [BLCaseDiscusionController::class, 'store']
        )
            ->middleware('permission:bl-case-discussion.store')
            ->name('bl.case-discussion.store');

    

        Route::get(
            '/{lead_uuid}',
            [BLCaseDiscusionController::class, 'show']
        )
            ->middleware('permission:bl-case-discussion.show')
            ->name('bl.case-discussion.show');

    });

});
