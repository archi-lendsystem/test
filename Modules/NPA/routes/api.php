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

        Route::post('/accounts/{accountUuid}/move-to-settlement', [NPAController::class, 'moveToSettlement'])
            ->whereUuid('accountUuid')
            ->middleware('permission:settlements.store')
            ->name('move-to-settlement');
    });
