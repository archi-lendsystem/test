<?php

use Illuminate\Support\Facades\Route;
use Modules\NOC\Http\Controllers\NOCController;

Route::middleware(['auth:sanctum', 'user.status'])
    ->prefix('v1/noc')
    ->name('noc.')
    ->group(function () {
        Route::get('/', [NOCController::class, 'index'])
            ->middleware('permission:noc.show')
            ->name('index');

        Route::post('/accounts/{accountUuid}', [NOCController::class, 'store'])
            ->whereUuid('accountUuid')
            ->middleware('permission:noc.store')
            ->name('store');

        Route::get('/{nocUuid}/download', [NOCController::class, 'download'])
            ->whereUuid('nocUuid')
            ->middleware('permission:noc.show')
            ->name('download');
    });
