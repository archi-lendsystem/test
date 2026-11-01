<?php

use Illuminate\Support\Facades\Route;
use Modules\DPD\Http\Controllers\DPDController;

Route::middleware(['auth:sanctum', 'user.status'])
    ->prefix('v1/dpd')
    ->name('dpd.')
    ->group(function () {
        Route::get('/', [DPDController::class, 'index'])
            ->middleware('permission:dpd.show')
            ->name('index');
    });
