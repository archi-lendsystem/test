<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Modules\Auth\Http\Controllers\AuthController;

Route::prefix('v1/auth')->group(function () {    

    Route::get('/config', [AuthController::class, 'loginConfig']);

    Route::post('/send/otp', [AuthController::class, 'sendOtp']);
    Route::post('/verify/otp', [AuthController::class, 'verifyOtp']);

    Route::post('/dlt/send/otp', [AuthController::class, 'sendDltOtp']);
    Route::post('/dlt/verify/otp', [AuthController::class, 'verifyDltOtp']);

    Route::post('/login/password',[AuthController::class, 'loginWithPassword']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);

        Route::get('/user', function (Request $request) {
            return $request->user();
        });
    });
});

   
