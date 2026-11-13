<?php

use Illuminate\Support\Facades\Route;
use Modules\Insurance\Http\Controllers\InsuranceController;

Route::middleware([
    'auth:sanctum',
    'user.status',
])->prefix('v1/insurances')->group(function () {
    Route::get('/{lead_uuid}', [InsuranceController::class, 'show'])
        ->whereUuid('lead_uuid')->middleware('permission:insurance');

    Route::post('/{lead_uuid}', [InsuranceController::class, 'store'])
        ->whereUuid('lead_uuid')->middleware('permission:insurance');

    Route::match(['put', 'patch'], '/{lead_uuid}', [
        InsuranceController::class,
        'update',
    ])->whereUuid('lead_uuid')->middleware('permission:insurance');
});
