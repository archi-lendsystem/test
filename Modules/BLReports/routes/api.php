<?php

// use Illuminate\Support\Facades\Route;
// use Modules\BLReports\Http\Controllers\BLReportsController;

// Route::middleware([
//     'auth:sanctum',
//     'user.status',
// ])
//     ->prefix('v1/bl')
//     ->name('bl.reports.')
//     ->group(function (): void {
//         Route::get(
//             'leads/{leadUuid}/reports',
//             [BLReportsController::class, 'index']
//         )
//             ->whereUuid('leadUuid')
//              ->middleware('permission:bl-Reports')
//             ->name('index');

//         Route::post(
//             'leads/{leadUuid}/reports/generate',
//             [BLReportsController::class, 'generate']
//         )
//             ->whereUuid('leadUuid')
//              ->middleware('permission:bl-Reports')
//             ->name('generate');

//         Route::get(
//             'leads/{leadUuid}/reports/{reportUuid}/download',
//             [BLReportsController::class, 'download']
//         )
//             ->whereUuid('leadUuid')
//             ->whereUuid('reportUuid')
//              ->middleware('permission:bl-Reports')
//             ->name('download');
//     });


use Illuminate\Support\Facades\Route;
use Modules\BLReports\Http\Controllers\BLReportsController;

Route::middleware([
    'auth:sanctum',
    'user.status',
])
    ->prefix('v1/bl')
    ->name('bl.reports.')
    ->group(function (): void {
        Route::get(
            'leads/{leadUuid}/reports',
            [BLReportsController::class, 'index']
        )
            ->whereUuid('leadUuid')
             ->middleware('permission:bl-Reports')
            ->name('index');

        Route::post(
            'leads/{leadUuid}/reports/generate',
            [BLReportsController::class, 'generate']
        )
            ->whereUuid('leadUuid')
             ->middleware('permission:bl-Reports')
            ->name('generate');

        Route::get(
            'leads/{leadUuid}/reports/{reportUuid}/download',
            [BLReportsController::class, 'download']
        )
            ->whereUuid('leadUuid')
            ->whereUuid('reportUuid')
             ->middleware('permission:bl-Reports')
            ->name('download');

        Route::get(
            'leads/{leadUuid}/reports/{reportUuid}/signed-download',
            [BLReportsController::class, 'downloadSigned']
        )
            ->whereUuid('leadUuid')
            ->whereUuid('reportUuid')
            ->middleware('permission:bl-Reports')
            ->name('signed-download');

        Route::post(
            'leads/{leadUuid}/reports/{reportUuid}/request-esign',
            [BLReportsController::class, 'requestESign']
        )
            ->whereUuid('leadUuid')
            ->whereUuid('reportUuid')
            ->middleware('permission:bl-Reports')
            ->name('request-esign');
    });