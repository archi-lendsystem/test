<?php

use Illuminate\Support\Facades\Route;
use Modules\Settlement\Http\Controllers\SettlementController;

Route::middleware(['auth:sanctum', 'user.status'])
    ->prefix('v1/settlements')
    ->name('settlements.')
    ->group(function () {
        Route::get('/', [SettlementController::class, 'index'])
            ->middleware('permission:settlements.show')
            ->name('index');

        Route::post('/accounts/{accountUuid}', [SettlementController::class, 'store'])
            ->whereUuid('accountUuid')
            ->middleware('permission:settlements.store')
            ->name('store');

        Route::post('/{settlementUuid}/approve', [SettlementController::class, 'approve'])
            ->whereUuid('settlementUuid')
            ->middleware('permission:settlements.approve')
            ->name('approve');

        Route::post('/{settlementUuid}/reject', [SettlementController::class, 'reject'])
            ->whereUuid('settlementUuid')
            ->middleware('permission:settlements.approve')
            ->name('reject');

        Route::post('/{settlementUuid}/mark-paid', [SettlementController::class, 'markPaid'])
            ->whereUuid('settlementUuid')
            ->middleware('permission:settlements.store')
            ->name('mark-paid');
    });
