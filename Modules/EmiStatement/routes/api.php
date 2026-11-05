<?php

use Illuminate\Support\Facades\Route;
use Modules\EmiStatement\Http\Controllers\EmiStatementController;

Route::middleware(['auth:sanctum'])->prefix('v1/emi-statements')->name('emi-statements.')->group(function () {
    Route::get('/', [EmiStatementController::class, 'index'])
        ->middleware('permission:emi-statement.show')
        ->name('index');

    Route::get('/accounts/{accountUuid}/summary', [EmiStatementController::class, 'summary'])
        ->middleware('permission:emi-statement.show')
        ->name('summary');

    Route::get('/accounts/{accountUuid}/history', [EmiStatementController::class, 'history'])
        ->middleware('permission:emi-statement.show')
        ->name('history');

    Route::post('/accounts/{accountUuid}/generate', [EmiStatementController::class, 'generate'])
        ->middleware('permission:emi-statement.store')
        ->name('generate');

    Route::get('/{statementUuid}/download', [EmiStatementController::class, 'download'])
        ->middleware('permission:emi-statement.show')
        ->name('download');
});
