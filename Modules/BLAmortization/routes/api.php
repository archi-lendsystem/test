<?php

use Illuminate\Support\Facades\Route;
use Modules\BLAmortization\Http\Controllers\BLAmortizationController;

Route::middleware([
    'auth:sanctum',
    'user.status',

])->prefix('v1')->group(function () {
    Route::prefix('bl-amortizations')->group(function () {
        Route::get('/', [BLAmortizationController::class, 'index']);

   

        Route::post('/{lead_uuid}', [BLAmortizationController::class, 'store'])
            ->middleware('permission:bl-amortization')
          ->whereUuid('lead_uuid');

        Route::get('/{lead_uuid}/pdf', [BLAmortizationController::class, 'downloadPdf'])
                    ->middleware('permission:bl-amortization')

            ->whereUuid('lead_uuid');

        Route::get('/{lead_uuid}', [BLAmortizationController::class, 'show'])
                    ->middleware('permission:bl-amortization')

            ->whereUuid('lead_uuid');

        Route::match(['put', 'patch'], '/{lead_uuid}', [BLAmortizationController::class, 'update'])
                    ->middleware('permission:bl-amortization')

            ->whereUuid('lead_uuid');
    });

 });