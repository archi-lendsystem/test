<?php

use Illuminate\Support\Facades\Route;
use Modules\LUC\Http\Controllers\LUCController;


Route::middleware('auth:sanctum')
    ->prefix('v1/luc')
    ->name('luc.')         
    ->group(function (): void {
        Route::get('/options', [LUCController::class, 'options'])
          ->middleware('permission:luc')
            ->name('options');
        Route::get('/eligibility/{lead_uuid}', [LUCController::class, 'eligibility'])
            ->whereUuid('lead_uuid')
              ->middleware('permission:luc')
            ->name('eligibility');

        Route::get('/', [LUCController::class, 'index'])
          ->middleware('permission:luc')
            ->name('index');
        Route::get('/{lead_uuid}', [LUCController::class, 'show'])
          ->middleware('permission:luc')
            ->whereUuid('lead_uuid')
            ->name('show');
        Route::get('/{lead_uuid}/photo', [LUCController::class, 'photo'])
            ->whereUuid('lead_uuid')
              ->middleware('permission:luc')
            ->name('photo');
        Route::post('/{lead_uuid}', [LUCController::class, 'store'])
            ->whereUuid('lead_uuid')
              ->middleware('permission:luc')
            ->name('store');
        Route::match(['put', 'patch'], '/{lead_uuid}', [LUCController::class, 'update'])
            ->whereUuid('lead_uuid')
              ->middleware('permission:luc')
            ->name('update');
        Route::patch('/{lead_uuid}/decision', [LUCController::class, 'decision'])
            ->whereUuid('lead_uuid')
              ->middleware('permission:luc')
            ->name('decision');
    });
