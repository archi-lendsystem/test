<?php

use Illuminate\Support\Facades\Route;
use Modules\BLSaction\Http\Controllers\BLSactionController;

Route::middleware([
    'auth:sanctum',
    'user.status',
])
->prefix('v1/bl/sactions')
->name('bl.sactions.')
->group(function () {


    Route::get(
        '/{lead_uuid}',
        [BLSactionController::class, 'show']
    )
    ->middleware('permission:bl-sanction.show')
    ->name('show');



    Route::put(
        '/{lead_uuid}',
        [BLSactionController::class, 'update']
    )
    ->middleware('permission:bl-sanction.update')
    ->name('update');

    Route::get(
        '/{lead_uuid}/download',
        [BLSactionController::class, 'download']
    )
    ->middleware('permission:bl-sanction.show')
    ->name('download');

    Route::post(
        '/{lead_uuid}/request-esign',
        [BLSactionController::class, 'requestESign']
    )
    ->middleware('permission:bl-sanction.update')
    ->name('request-esign');

    Route::post(
        '/{lead_uuid}/confirm',
        [BLSactionController::class, 'confirm']
    )
    ->middleware('permission:bl-sanction.update')
    ->name('confirm');

});