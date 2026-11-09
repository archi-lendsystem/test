<?php

use Illuminate\Support\Facades\Route;
use Modules\Foreclosure\Http\Controllers\ForeclosureController;

Route::middleware(['auth:sanctum', 'user.status'])
    ->prefix('v1/foreclosure')
    ->name('foreclosure.')
    ->group(function (): void {
        Route::get('/', [ForeclosureController::class, 'index'])
            ->middleware('permission:foreclosure.show')
            ->name('index');

        Route::get('/cases', [ForeclosureController::class, 'cases'])
            ->middleware('permission:foreclosure.show')
            ->name('cases.index');

        Route::get('/cases/{foreclosureUuid}/download', [ForeclosureController::class, 'download'])
            ->whereUuid('foreclosureUuid')
            ->middleware('permission:foreclosure.show')
            ->name('cases.download');

        Route::post('/accounts/{accountUuid}', [ForeclosureController::class, 'store'])
            ->whereUuid('accountUuid')
            ->middleware('permission:foreclosure.store')
            ->name('store');
    });