<?php

use Illuminate\Support\Facades\Route;
use Modules\Charges\Http\Controllers\ChargesController;

Route::middleware([
    'auth:sanctum',
    'user.status',
])->prefix('v1/charges')->group(function () {

   
    
    Route::get('/{lead_uuid}', [
        ChargesController::class,
        'show',
    ])->whereUuid('lead_uuid')->middleware('permission:charges');

    Route::post('/{lead_uuid}', [
        ChargesController::class,
        'store',
    ])->whereUuid('lead_uuid')->middleware('permission:charges');

    Route::match(['put', 'patch'], '/{lead_uuid}', [
        ChargesController::class,
        'update',
    ])->whereUuid('lead_uuid')->middleware('permission:charges');


});
