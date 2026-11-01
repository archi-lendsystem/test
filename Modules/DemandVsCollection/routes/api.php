<?php

use Illuminate\Support\Facades\Route;
use Modules\DemandVsCollection\Http\Controllers\DemandVsCollectionController;

Route::middleware(['auth:sanctum'])
    ->prefix('v1')
    ->name('demand-vs-collection.')
    ->group(function (): void {
        Route::get(
            'demand-vs-collection',
            [DemandVsCollectionController::class, 'index']
        )
        ->middleware('permission:demandVscollection-report.show')->name('index');
    });
