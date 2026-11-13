<?php

use Illuminate\Support\Facades\Route;
use Modules\BLCreditVisitReport\Http\Controllers\BLCreditVisitReportController;

Route::middleware([
    'auth:sanctum',
    'user.status',
])->prefix('v1/bl')->group(function () {

 

    Route::get(
        'credit-visit-reports/{lead_uuid}',
        [BLCreditVisitReportController::class, 'index']
    )
        ->middleware('permission:bl-credit-visit-report.show')
        ->name('blcreditvisitreport.index');

    Route::post(
        'credit-visit-reports/{lead_uuid}',
        [BLCreditVisitReportController::class, 'store']
    )
        ->middleware('permission:bl-credit-visit-report.store')
        ->name('blcreditvisitreport.store');

    Route::get(
        'credit-visit-reports/{lead_uuid}/{id}',
        [BLCreditVisitReportController::class, 'show']
    )
        ->middleware('permission:bl-credit-visit-report.show')
        ->name('blcreditvisitreport.show');

});
