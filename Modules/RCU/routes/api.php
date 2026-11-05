<?php

use Illuminate\Support\Facades\Route;
use Modules\RCU\Http\Controllers\RCUController;

Route::middleware(['auth:sanctum', 'user.status'])
    ->prefix('v1/rcu')
    ->name('rcu.')
    ->group(function () {
        Route::get('/{leadUuid}/documents', [RCUController::class, 'documents'])
            ->middleware('permission:rcu.show')
            ->name('documents');

        Route::post('/{leadUuid}/documents/{uploadUuid}/verify', [RCUController::class, 'verifyDocument'])
            ->middleware('permission:rcu.verify-document')
            ->name('verify-document');

        Route::post('/{leadUuid}/approve', [RCUController::class, 'approve'])
            ->middleware('permission:rcu.approve')
            ->name('approve');

        Route::post('/{leadUuid}/reject', [RCUController::class, 'reject'])
            ->middleware('permission:rcu.reject')
            ->name('reject');
    });
