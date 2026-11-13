<?php

use Illuminate\Support\Facades\Route;
use Modules\BranchMonitoring\Http\Controllers\BranchMonitoringController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('branchmonitorings', BranchMonitoringController::class)->names('branchmonitoring');
});
