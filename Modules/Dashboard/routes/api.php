<?php

use Illuminate\Support\Facades\Route;
use Modules\Dashboard\Http\Controllers\DashboardController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {

    Route::prefix('dashboard')->name('dashboard.')->group(function () {

        Route::get('summary', [DashboardController::class, 'productSummary'])->name('summary');
        Route::get('leads', [DashboardController::class, 'productLeadsList'])->name('leads');

    });

});