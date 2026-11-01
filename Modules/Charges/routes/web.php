<?php

use Illuminate\Support\Facades\Route;
use Modules\Charges\Http\Controllers\ChargesController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('charges', ChargesController::class)->names('charges');
});
