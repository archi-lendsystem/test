<?php

use Illuminate\Support\Facades\Route;
use Modules\BLCam\Http\Controllers\BLCamController;


Route::prefix('v1/BL/cam')
    ->middleware([
        'auth:sanctum',
        'user.status',
    ])
    ->group(function () {

     

         Route::get('/find/{leadUuid}', [BLCamController::class, 'getByLead'])
         ->middleware('permission:bl-cam.show');

        Route::get(
            '/{lead_uuid}',
            [BLCamController::class, 'show']
        )
        ->middleware('permission:bl-cam.show')
        ->name('blcam.show');



        Route::post(
            '/{lead_uuid}',
            [BLCamController::class, 'store']
        )
        ->middleware('permission:bl-cam.store')
        ->name('blcam.store');

       

        
       


    });