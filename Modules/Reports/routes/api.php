<?php

use Illuminate\Support\Facades\Route;
use Modules\Reports\Http\Controllers\ReportsController;

Route::middleware(['auth:sanctum'])
    ->prefix('v1/reports')
    ->name('api.reports.')
    ->group(function () {
        Route::get(
            '/leads',
            [ReportsController::class, 'index']
        )->name('leads.index');

        Route::get(
            '/leads/export',
            [ReportsController::class, 'export']
        )->name('leads.export');
    });