<?php

use Illuminate\Support\Facades\Route;
use Modules\LUCReport\Http\Controllers\LUCReportController;

Route::prefix('v1/luc-report')
    ->middleware(['api', 'auth:sanctum'])
    ->group(function (): void {
        Route::get('/', [LUCReportController::class, 'index'])->middleware('permission:luc-reports');                       // 1. all customers + LUC status
        Route::get('{lead_uuid}', [LUCReportController::class, 'show'])->middleware('permission:luc-reports');               // 2. view detail
        Route::get('{lead_uuid}/download', [LUCReportController::class, 'download'])->middleware('permission:luc-reports');  // 3. PDF download (must come before {lead_uuid})
    });
 
