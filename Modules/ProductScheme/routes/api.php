<?php

use Illuminate\Support\Facades\Route;
use Modules\ProductScheme\Http\Controllers\ProductSchemeController;

Route::middleware(['auth:sanctum'])
    ->prefix('v1/product-configuration')
    ->group(function () {

        /*
        |--------------------------------------------------------------------------
        | Product Scheme Checklist Options
        |--------------------------------------------------------------------------
        |
        | Used by frontend to load dropdowns and form metadata.
        |
        */
        Route::get('/scheme-options', [ProductSchemeController::class, 'options'])
            ->name('product-configuration.scheme-options');

        /*
        |--------------------------------------------------------------------------
        | Product Scheme Checklist 
        |--------------------------------------------------------------------------
        |
        | Product-wise scheme/rule configuration.
        |
        */
        Route::get('/product-schemes', [ProductSchemeController::class, 'index'])
            ->name('product-configuration.product-schemes.index');

        Route::post('/product-schemes', [ProductSchemeController::class, 'store'])
            ->name('product-configuration.product-schemes.store');

        Route::get('/product-schemes/{productScheme:uuid}', [ProductSchemeController::class, 'show'])
            ->name('product-configuration.product-schemes.show');

        Route::put('/product-schemes/{productScheme:uuid}', [ProductSchemeController::class, 'update'])
            ->name('product-configuration.product-schemes.update');

        Route::patch('/product-schemes/{productScheme:uuid}', [ProductSchemeController::class, 'update'])
            ->name('product-configuration.product-schemes.patch');

        Route::delete('/product-schemes/{productScheme:uuid}', [ProductSchemeController::class, 'destroy'])
            ->name('product-configuration.product-schemes.destroy');
    });