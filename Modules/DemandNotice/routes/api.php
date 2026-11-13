<?php

use Illuminate\Support\Facades\Route;
use Modules\DemandNotice\Http\Controllers\DemandNoticeController;

Route::middleware(['auth:sanctum'])->prefix('v1/demand-notices')->name('demand-notices.')->group(function () {
    Route::get('/', [DemandNoticeController::class, 'index'])
        ->middleware('permission:demand-notice.show')
        ->name('index');

    Route::get('/accounts/{accountUuid}/history', [DemandNoticeController::class, 'history'])
        ->middleware('permission:demand-notice.show')
        ->name('history');

    Route::post('/accounts/{accountUuid}', [DemandNoticeController::class, 'store'])
        ->middleware('permission:demand-notice.store')
        ->name('store');

    Route::post('/{noticeUuid}/mark-sent', [DemandNoticeController::class, 'markSent'])
        ->middleware('permission:demand-notice.store')
        ->name('mark-sent');

    Route::get('/{noticeUuid}/download', [DemandNoticeController::class, 'download'])
        ->middleware('permission:demand-notice.show')
        ->name('download');
});
