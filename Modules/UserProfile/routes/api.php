<?php

use Illuminate\Support\Facades\Route;
use Modules\UserProfile\Http\Controllers\UserProfileController;


Route::middleware(['auth:sanctum'])
    ->prefix('v1')
    ->group(function () {

        Route::get('user/profile', [UserProfileController::class, 'getProfile'])
            ->name('user-profile');

            Route::post('/change-password', [UserProfileController::class, 'changePassword']);

    });