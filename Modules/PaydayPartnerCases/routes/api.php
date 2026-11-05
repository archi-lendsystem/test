<?php

use Illuminate\Support\Facades\Route;
use Modules\PaydayLoanPartner\Http\Middleware\PartnerPullAuthMiddleware;
use Modules\PaydayPartnerCases\Http\Controllers\PartnerPaydayDisbursementController;
use Modules\PaydayPartnerCases\Http\Controllers\PaydayPartnerCasesController;

/*
|--------------------------------------------------------------------------
| Internal Sabrimala Admin APIs
|--------------------------------------------------------------------------
| Auth required.
*/
Route::middleware(['auth:sanctum', 'user.status'])
    ->prefix('v1/payday/partner/cases')
    ->group(function () {
        Route::get('/', [PaydayPartnerCasesController::class, 'index']);
        Route::post('/', [PaydayPartnerCasesController::class, 'store']);

        Route::post('/sync', [PaydayPartnerCasesController::class, 'sync']);
        Route::post('/webhook', [PaydayPartnerCasesController::class, 'webhook']);

        Route::post('/{id}/send-to-partner', [PaydayPartnerCasesController::class, 'sendToPartner']);

        Route::get('/{id}', [PaydayPartnerCasesController::class, 'show']);
        Route::post('/{id}/disbursement', [PaydayPartnerCasesController::class, 'disbursement']);
        Route::post('/{id}/retry', [PaydayPartnerCasesController::class, 'retry']);
    });

/*
|--------------------------------------------------------------------------
| Partner Pull APIs
|--------------------------------------------------------------------------
| Auth:sanctum nahi lagega.
| Partner headers se authenticate hoga:
| X-Partner-Code, X-Client-Id, X-Client-Secret
*/
Route::middleware([PartnerPullAuthMiddleware::class])
    ->prefix('v1/partner/payday')
    ->group(function () {
        Route::get('/disbursements', [PartnerPaydayDisbursementController::class, 'index']);
        Route::post('/disbursements/ack', [PartnerPaydayDisbursementController::class, 'ack']);
    });