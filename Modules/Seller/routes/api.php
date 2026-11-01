<?php

use Illuminate\Support\Facades\Route;
use Modules\Seller\Http\Controllers\SellerController;

Route::middleware('auth:sanctum')
    ->prefix('v1/leads/{lead_uuid}/sellers')
    ->group(function () {
        Route::get('/', [SellerController::class, 'index']);
        Route::post('/', [SellerController::class, 'store']);
        // Route::get('/{seller_id}', [SellerController::class, 'show']);
        // Route::put('/{seller_id}', [SellerController::class, 'update']);
        // Route::patch('/{seller_id}', [SellerController::class, 'update']);
        Route::delete('/{seller_id}', [SellerController::class, 'destroy']);
    });










