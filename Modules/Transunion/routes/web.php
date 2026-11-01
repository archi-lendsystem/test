<?php

use Illuminate\Support\Facades\Route;
use Modules\Transunion\Http\Controllers\TransunionController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('transunions', TransunionController::class)->names('transunion');
});
