<?php

use Illuminate\Support\Facades\Route;
use Modules\Customers\Http\Controllers\CustomersController;

Route::middleware(['auth:sanctum', 'user.status'])->prefix('v1')->group(function () {

    Route::get('/customers', [CustomersController::class, 'index'])
        ->middleware('permission:customers')
        ->name('customers.index');

    Route::get('/customers/export', [CustomersController::class, 'export'])
        ->middleware('permission:customers')
        ->name('customers.export');

    Route::get('/customers/{customer_application_id}', [CustomersController::class, 'show'])
        ->middleware('permission:customers')
        ->name('customers.show');

});