<?php

use Illuminate\Support\Facades\Route;
use Modules\Repayment\Http\Controllers\RepaymentAccountController;
use Modules\Repayment\Http\Controllers\RepaymentChargeController;
use Modules\Repayment\Http\Controllers\RepaymentPaymentController;
use Modules\Repayment\Http\Controllers\RepaymentPaymentReversalController;
use Modules\Repayment\Http\Controllers\RepaymentReceiptController;
use Modules\Repayment\Http\Controllers\RepaymentImportController;

Route::middleware(['auth:sanctum', 'user.status'])
    ->prefix('v1/repayment')
    ->name('repayment.')
    ->group(function (): void {
        /*
         * ============================================================
         * Bulk repayment collection Excel import
         * ============================================================
         *
         * ExcelJS frontend parses the .xlsx file and sends JSON rows.
         *
         * validate:
         * - validates complete Excel data
         * - creates repayment_imports
         * - creates repayment_import_rows
         * - does NOT post payment
         *
         * show:
         * - fetches validation/import preview
         * - returns row-level validation errors
         */
        Route::get(
            'imports/template-data',
            [RepaymentImportController::class, 'templateData']
        )->middleware('permission:repayment.show')->name('imports.template-data');

        Route::post(
            'imports/validate',
            [RepaymentImportController::class, 'validateImport']
        )->middleware('permission:repayment.store')->name('imports.validate');

        Route::post(
            'imports/{importUuid}/confirm',
            [RepaymentImportController::class, 'confirm']
        )
            ->whereUuid('importUuid')
            ->middleware('permission:repayment.store')
            ->name('imports.confirm');

        Route::get(
            'imports/{importUuid}',
            [RepaymentImportController::class, 'show']
        )
            ->whereUuid('importUuid')
            ->middleware('permission:repayment.show')
            ->name('imports.show');
        Route::get(
            'accounts',
            [RepaymentAccountController::class, 'index']
        )->middleware('permission:repayment.show')->name('accounts.index');

        Route::post(
            'accounts/{accountUuid}/payments',
            [RepaymentPaymentController::class, 'store']
        )
            ->whereUuid('accountUuid')
            ->middleware('permission:repayment.store')
            ->name('accounts.payments.store');

        Route::get(
            'accounts/{accountUuid}/installments',
            [RepaymentAccountController::class, 'installments']
        )
            ->whereUuid('accountUuid')
            ->middleware('permission:repayment.show')
            ->name('accounts.installments.index');

        Route::get(
            'accounts/{accountUuid}/transactions',
            [RepaymentAccountController::class, 'transactions']
        )
            ->whereUuid('accountUuid')
            ->middleware('permission:repayment.show')
            ->name('accounts.transactions.index');

        /*
         * Issue or return an existing payment receipt.
         */
        Route::post(
            'accounts/{accountUuid}/transactions/{transactionUuid}/receipt',
            [RepaymentReceiptController::class, 'store']
        )
            ->whereUuid('accountUuid')
            ->whereUuid('transactionUuid')
            ->middleware('permission:repayment.store')
            ->name('accounts.transactions.receipt.store');

        /*
         * Fetch an existing payment receipt.
         */
        Route::get(
            'accounts/{accountUuid}/transactions/{transactionUuid}/receipt',
            [RepaymentReceiptController::class, 'show']
        )
            ->whereUuid('accountUuid')
            ->whereUuid('transactionUuid')
            ->middleware('permission:repayment.show')
            ->name('accounts.transactions.receipt.show');

        /*
         * Download the receipt PDF from private storage.
         */
        Route::get(
            'accounts/{accountUuid}/transactions/{transactionUuid}/receipt/download',
            [RepaymentReceiptController::class, 'download']
        )
            ->whereUuid('accountUuid')
            ->whereUuid('transactionUuid')
            ->middleware('permission:repayment.show')
            ->name('accounts.transactions.receipt.download');

        Route::post(
            'accounts/{accountUuid}/transactions/{transactionUuid}/reversal',
            [RepaymentPaymentReversalController::class, 'store']
        )
            ->whereUuid('accountUuid')
            ->whereUuid('transactionUuid')
            ->middleware('permission:repayment.store')
            ->name('accounts.transactions.reversal.store');

        /*
         * Bounce / penal charges and their waiver.
         */
        Route::get(
            'accounts/{accountUuid}/charges',
            [RepaymentChargeController::class, 'index']
        )
            ->whereUuid('accountUuid')
            ->middleware('permission:repayment.show')
            ->name('accounts.charges.index');

        Route::post(
            'accounts/{accountUuid}/charges/bounce',
            [RepaymentChargeController::class, 'storeBounce']
        )
            ->whereUuid('accountUuid')
            ->middleware('permission:repayment.store')
            ->name('accounts.charges.bounce.store');

        Route::post(
            'accounts/{accountUuid}/charges/penal',
            [RepaymentChargeController::class, 'storePenal']
        )
            ->whereUuid('accountUuid')
            ->middleware('permission:repayment.store')
            ->name('accounts.charges.penal.store');

        Route::post(
            'accounts/{accountUuid}/charges/foreclosure',
            [RepaymentChargeController::class, 'storeForeclosure']
        )
            ->whereUuid('accountUuid')
            ->middleware('permission:repayment.store')
            ->name('accounts.charges.foreclosure.store');

        Route::post(
            'charges/{chargeUuid}/waive',
            [RepaymentChargeController::class, 'waive']
        )
            ->whereUuid('chargeUuid')
            ->middleware('permission:repayment.store')
            ->name('charges.waive');

        Route::get(
            'accounts/{accountUuid}',
            [RepaymentAccountController::class, 'show']
        )
            ->whereUuid('accountUuid')
            ->middleware('permission:repayment.show')
            ->name('accounts.show');
    });