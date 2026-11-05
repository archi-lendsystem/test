<?php

use Illuminate\Support\Facades\Route;
use Modules\ESign\Http\Controllers\BackendDocumentController;
use Modules\ESign\Http\Controllers\BLCombinedESignController;
use Modules\ESign\Http\Controllers\CustomerDocumentController;
use Modules\ESign\Http\Controllers\WebhookController;

Route::prefix((string) config('esign.routes.backend_prefix'))
    ->middleware((array) config('esign.routes.backend_middleware'))
    ->as('esign.backend.')
    ->group(function (): void {
        Route::get('/documents', [BackendDocumentController::class, 'index'])->name('documents.index');
        Route::post('/documents', [BackendDocumentController::class, 'store'])->name('documents.store');
        Route::get('/documents/{document}', [BackendDocumentController::class, 'show'])->name('documents.show');
        Route::post('/documents/{document}/borrower-session', [BackendDocumentController::class, 'borrower'])->name('documents.borrower');
        Route::post('/documents/{document}/lender-session', [BackendDocumentController::class, 'lender'])->name('documents.lender');
        Route::get('/documents/{document}/download/{type}', [BackendDocumentController::class, 'download'])
            ->where('type', 'original|borrower|final')
            ->name('documents.download');
        Route::post('/sessions/{session}/sync', [BackendDocumentController::class, 'sync'])->name('sessions.sync');
        Route::post('/bl/{leadUuid}/combined-signing', BLCombinedESignController::class)
            ->whereUuid('leadUuid')
            ->name('bl.combined-signing');
    });

Route::prefix((string) config('esign.routes.customer_prefix'))
    ->middleware((array) config('esign.routes.customer_middleware'))
    ->as('esign.customer.')
    ->group(function (): void {
        Route::get('/customer-lendings/{customerLendingId}/document', [CustomerDocumentController::class, 'latest'])
            ->whereNumber('customerLendingId')
            ->name('documents.latest');
        Route::get('/documents/{document}', [CustomerDocumentController::class, 'show'])->name('documents.show');
        Route::post('/documents/{document}/borrower-session', [CustomerDocumentController::class, 'borrower'])->name('documents.borrower');
        Route::get('/documents/{document}/borrower-signed', [CustomerDocumentController::class, 'borrowerDownload'])
            ->name('documents.borrower-download');
    });

Route::prefix((string) config('esign.routes.webhook_prefix'))
    ->middleware((array) config('esign.routes.webhook_middleware'))
    ->group(function (): void {
        Route::post('/webhook', WebhookController::class)->name('esign.webhook');
    });


 
