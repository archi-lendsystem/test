<?php

use Illuminate\Support\Facades\Route;
use Modules\ESign\Http\Controllers\ESignController;
use Modules\ESign\Http\Controllers\NachMandateController;

Route::middleware(['auth:sanctum'])->prefix('v1/esign')->group(function () {

    Route::post('/initiate', [ESignController::class, 'initiate'])
        ->name('esign.initiate');
    Route::get('/status/{requestId}', [ESignController::class, 'status'])
        ->name('esign.status');
});
Route::post('/esign/webhook', [ESignController::class, 'handleWebhook'])
    ->name('esign.webhook');

Route::middleware(['auth:sanctum', 'user.status'])
    ->prefix('v1/bl/enach')
    ->name('bl.enach.')
    ->group(function () {
        Route::get('/{lead_uuid}', [NachMandateController::class, 'show'])
            ->middleware('permission:bl-enach.show')
            ->name('show');
        Route::post('/{lead_uuid}/initiate', [NachMandateController::class, 'initiate'])
            ->middleware('permission:bl-enach.store')
            ->name('initiate');
        Route::post('/{lead_uuid}/cancel', [NachMandateController::class, 'cancel'])
            ->middleware('permission:bl-enach.cancel')
            ->name('cancel');
    });

Route::post('/esign/nach/webhook', [NachMandateController::class, 'handleWebhook'])
    ->name('esign.nach.webhook');
