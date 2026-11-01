<?php

use Illuminate\Support\Facades\Route;
use Modules\Crif\Http\Controllers\CrifController;

Route::middleware([
    'auth:sanctum',
])
    ->prefix('v1/crif')
    ->name('crif.')
    ->group(function (): void {

        /*
        |--------------------------------------------------------------------------
        | All Applicant / Co-applicant / Guarantor
        |--------------------------------------------------------------------------
        */

        Route::get(
            '/applicants/{leadUuid}',
            [
                CrifController::class,
                'applicantsByLead',
            ]
        )
            ->whereUuid('leadUuid')
            ->name(
                'applicants.index'
            );

        /*
        |--------------------------------------------------------------------------
        | Run CRIF for ONE selected person
        |--------------------------------------------------------------------------
        */

        Route::post(
            '/verifications',
            [
                CrifController::class,
                'store',
            ]
        )->name(
            'verifications.store'
        );

        /*
        |--------------------------------------------------------------------------
        | All latest CRIF scores of lead
        |--------------------------------------------------------------------------
        */

        Route::get(
            '/leads/{leadUuid}/reports',
            [
                CrifController::class,
                'reportsByLead',
            ]
        )
            ->whereUuid('leadUuid')
            ->name(
                'lead.reports'
            );

        /*
        |--------------------------------------------------------------------------
        | Single report
        |--------------------------------------------------------------------------
        */

        Route::get(
            '/reports/{uuid}',
            [
                CrifController::class,
                'show',
            ]
        )
            ->whereUuid('uuid')
            ->name(
                'reports.show'
            );

            Route::get(
    '/reports/{uuid}/pdf',
    [
        CrifController::class,
        'downloadPdf',
    ]
)
    ->whereUuid('uuid')
    ->name('reports.pdf');


    Route::get(
    '/reports/{uuid}',
    [
        CrifController::class,
        'show',
    ]
)
    ->whereUuid('uuid')
    ->name('reports.show');
    });