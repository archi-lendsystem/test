<?php

use Illuminate\Support\Facades\Route;
use Modules\BLDisbursment\Http\Controllers\BLDisbursmentController;

Route::middleware(['auth:sanctum','user.status'])->prefix('v1/bl')->group(function () {

  
    Route::get('/lead/details/{lead_uuid}', [BLDisbursmentController::class, 'showDetails'])
        ->middleware('permission:bl-disbursment.show');

    Route::post('/lead/{lead_uuid}/disbursement', [BLDisbursmentController::class, 'store'])
        ->middleware('permission:bl-disbursment.store');

   

});