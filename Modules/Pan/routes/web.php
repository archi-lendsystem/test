<?php

use Illuminate\Support\Facades\Route;
use Modules\Pan\Http\Controllers\PanController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('pans', PanController::class)->names('pan');
});
