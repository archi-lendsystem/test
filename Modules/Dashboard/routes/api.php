<?php

use Illuminate\Support\Facades\Route;
use Modules\Dashboard\Http\Controllers\DashboardController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {

    Route::prefix('dashboard')->name('dashboard.')->group(function () {

        // ── BL (Business Loan) Dashboard ──────────────────────────────────────
        Route::prefix('bl')->name('bl.')->group(function () {
            Route::get('summary', [DashboardController::class, 'blSummary'])->name('summary');
            Route::get('leads',   [DashboardController::class, 'blLeadsList'])->name('leads');
        });

    });

});