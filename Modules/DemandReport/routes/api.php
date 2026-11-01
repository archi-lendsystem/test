<?php

use Illuminate\Support\Facades\Route;
use Modules\DemandReport\Http\Controllers\DemandReportController;

Route::middleware(['auth:sanctum'])->prefix('v1/demand-report')->name('demand-report.')->group(function () {
    Route::get('/', [DemandReportController::class, 'index'])
        ->middleware('permission:demand-report.show')
        ->name('index');
});
