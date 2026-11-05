<?php

use Illuminate\Support\Facades\Route;
use Modules\Experian\Http\Controllers\ExperianController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('experians', ExperianController::class)->names('experian');
});
