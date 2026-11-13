<?php

use Illuminate\Support\Facades\Route;
use Modules\FollowUpPTP\Http\Controllers\FollowUpController;

Route::middleware(['auth:sanctum', 'user.status'])
    ->prefix('v1/follow-ups')
    ->name('follow-ups.')
    ->group(function () {
        Route::get('/', [FollowUpController::class, 'index'])
            ->middleware('permission:follow-ups.show')
            ->name('index');

        Route::get('/accounts/{accountUuid}/history', [FollowUpController::class, 'history'])
            ->whereUuid('accountUuid')
            ->middleware('permission:follow-ups.show')
            ->name('history');

        Route::post('/accounts/{accountUuid}', [FollowUpController::class, 'store'])
            ->whereUuid('accountUuid')
            ->middleware('permission:follow-ups.store')
            ->name('store');

        Route::post('/{followUpUuid}/resolve-ptp', [FollowUpController::class, 'resolvePtp'])
            ->whereUuid('followUpUuid')
            ->middleware('permission:follow-ups.store')
            ->name('resolve-ptp');
    });
