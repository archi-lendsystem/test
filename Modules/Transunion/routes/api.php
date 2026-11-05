<?php

use Illuminate\Support\Facades\Route;
use Modules\Transunion\Http\Controllers\TransunionController;

Route::middleware([
    'auth:sanctum',
])
    ->prefix('v1/transunion')
    ->name('transunion.')
    ->group(function (): void {
        Route::get(
            '/applicants/{leadUuid}',
            [TransunionController::class, 'applicantsByLead']
        )
            ->whereUuid('leadUuid')
            ->name('applicants.index');

        Route::post(
            '/verifications',
            [TransunionController::class, 'store']
        )->name('verifications.store');

        Route::get(
            '/leads/{leadUuid}/reports',
            [TransunionController::class, 'reportsByLead']
        )
            ->whereUuid('leadUuid')
            ->name('lead.reports');

        Route::get(
            '/reports/{transunion}/pdf',
            [TransunionController::class, 'pdf']
        )->name('reports.pdf');

        Route::get(
            '/reports/{transunion}/replay',
            [TransunionController::class, 'replay']
        )->name('reports.replay');

        Route::get(
            '/reports/{transunion}',
            [TransunionController::class, 'show']
        )->name('reports.show');
    });
