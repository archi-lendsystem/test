<?php

use Illuminate\Support\Facades\Route;
use Modules\Insurance\Http\Controllers\InsuranceController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('insurances', InsuranceController::class)->names('insurance');
});
