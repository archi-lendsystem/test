<?php

use Illuminate\Support\Facades\Route;
use Modules\LUCReport\Http\Controllers\LUCReportController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('lucreports', LUCReportController::class)->names('lucreport');
});
