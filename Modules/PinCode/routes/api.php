<?php

use Illuminate\Support\Facades\Route;
use Modules\PinCode\Http\Controllers\PinCodeController;

Route::middleware(['auth:sanctum','user.status'])->prefix('v1')->group(function () {
    // Route::apiResource('pincodes', PinCodeController::class)->names('pincode');
    Route::get('/location/{pincode}', [PinCodeController::class, 'getLocation']);
});



