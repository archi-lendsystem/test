<?php

use Illuminate\Support\Facades\Route;
use Modules\Experian\Http\Controllers\ExperianController;

Route::middleware([
    'auth:sanctum',
])
    ->prefix('v1/experian')
    ->name('experian.')
    ->group(function (): void {

        /*
        |--------------------------------------------------------------------------
        | Applicant / Co-applicant / Guarantor
        |--------------------------------------------------------------------------
        */

        Route::get(
            '/applicants/{leadUuid}',
            [
                ExperianController::class,
                'applicantsByLead',
            ]
        )
            ->whereUuid(
                'leadUuid'
            )
            ->name(
                'applicants.index'
            );

        /*
        |--------------------------------------------------------------------------
        | Run Experian
        |--------------------------------------------------------------------------
        */

        Route::post(
            '/verifications',
            [
                ExperianController::class,
                'store',
            ]
        )
            ->name(
                'verifications.store'
            );

        /*
        |--------------------------------------------------------------------------
        | Latest report per applicant
        |--------------------------------------------------------------------------
        */

        Route::get(
            '/leads/{leadUuid}/reports',
            [
                ExperianController::class,
                'reportsByLead',
            ]
        )
            ->whereUuid(
                'leadUuid'
            )
            ->name(
                'lead.reports'
            );

        /*
        |--------------------------------------------------------------------------
        | Download PDF
        |--------------------------------------------------------------------------
        */

        Route::get(
            '/reports/{uuid}/pdf',
            [
                ExperianController::class,
                'downloadPdf',
            ]
        )
            ->whereUuid(
                'uuid'
            )
            ->name(
                'reports.pdf'
            );

        /*
        |--------------------------------------------------------------------------
        | Single Experian Report
        |--------------------------------------------------------------------------
        */

        Route::get(
            '/reports/{uuid}',
            [
                ExperianController::class,
                'show',
            ]
        )
            ->whereUuid(
                'uuid'
            )
            ->name(
                'reports.show'
            );
    });