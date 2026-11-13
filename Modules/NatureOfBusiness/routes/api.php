<?php

use Illuminate\Support\Facades\Route;
use Modules\NatureOfBusiness\Http\Controllers\NatureOfBusinessController;

Route::middleware(['auth:sanctum', 'user.status'])
    ->prefix('v1')
    ->group(function () {
         Route::get('/lap/nature-of-business', [NatureOfBusinessController::class, 'index']);
      
    });
   