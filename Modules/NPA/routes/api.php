<?php

use Illuminate\Support\Facades\Route;
use Modules\NPA\Http\Controllers\NPAController;

Route::middleware(['auth:sanctum', 'user.status'])
    ->prefix('v1/npa')
    ->name('npa.')
    ->group(function () {
        Route::get('/', [NPAController::class, 'index'])
            ->middleware('permission:npa.show')
            ->name('index');
    });
