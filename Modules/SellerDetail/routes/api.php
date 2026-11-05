<?php

use Illuminate\Support\Facades\Route;
use Modules\SellerDetail\Http\Controllers\SellerDetailController;

Route::middleware('auth:sanctum')
    ->prefix('/v1/leads/{lead_uuid}/sellers/{seller_id}/detail')
    ->group(function () {
        Route::get('/', [SellerDetailController::class, 'show']);
        Route::post('/', [SellerDetailController::class, 'store']);
        Route::put('/', [SellerDetailController::class, 'update']);
        Route::delete('/', [SellerDetailController::class, 'destroy']);
        Route::get('/documents/{type}', [SellerDetailController::class, 'document'])
            ->name('seller-detail.document');
    });
