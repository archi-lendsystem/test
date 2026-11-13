<?php

use Illuminate\Support\Facades\Route;
use Modules\BranchMonitoring\Http\Controllers\BranchMonitoringController;

Route::middleware(['auth:sanctum', 'user.status'])->prefix('v1')->group(function () {

    Route::get('/branch-monitoring', [BranchMonitoringController::class, 'index'])
        ->middleware('permission:branch-monitoring')
        ->name('branch-monitoring.index');

});
