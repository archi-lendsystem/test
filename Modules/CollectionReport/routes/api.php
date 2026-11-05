<?php

use Illuminate\Support\Facades\Route;
use Modules\CollectionReport\Http\Controllers\CollectionReportController;

Route::middleware(['auth:sanctum'])->prefix('v1/collection-report')->name('collection-report.')->group(function () {
    Route::get('/summary', [CollectionReportController::class, 'summary'])
        ->middleware('permission:lms.collection_report')
        ->name('summary');


    Route::get(
        'repayment-summary',
        [CollectionReportController::class, 'repaymentSummary']
    )
    ->middleware('permission:lms.collection_report')->name('repayment-summary');


    Route::get(
        'pos-report',
        [CollectionReportController::class, 'posReport']
    )->middleware('permission:lms.pos_report')->name('pos-report');


    Route::get(
        'disbursement-report',
        [CollectionReportController::class, 'disbursementReport']
    )->middleware('permission:lms.disbursment_report')->name('disbursement-report');

    Route::get('cic-report', [CollectionReportController::class, 'cicReport'])->middleware('permission:lms.cic_report')->name('cic-report');
});
