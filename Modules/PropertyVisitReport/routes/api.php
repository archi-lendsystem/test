<?php

use Illuminate\Support\Facades\Route;
use Modules\PropertyVisitReport\Http\Controllers\PropertyVisitReportController;

Route::prefix('property-visit-reports')
    ->group(function () {

        /*
         * Check LAP / HL first.
         *
         * Frontend can call this API to decide
         * whether Property Visit Report option
         * should be displayed.
         */
        Route::get(
            '/{leadUuid}/eligibility',
            [
                PropertyVisitReportController::class,
                'eligibility'
            ]
        )
            ->whereUuid('leadUuid');


        /*
         * List
         */
        Route::get(
            '/',
            [
                PropertyVisitReportController::class,
                'index'
            ]
        );


        /*
         * Create by Lead UUID
         */
        Route::post(
            '/{leadUuid}',
            [
                PropertyVisitReportController::class,
                'store'
            ]
        )
            ->whereUuid('leadUuid');


        /*
         * Private document
         */
        Route::get(
            '/{leadUuid}/documents/{index}',
            [
                PropertyVisitReportController::class,
                'document'
            ]
        )
            ->whereUuid('leadUuid')
            ->whereNumber('index')
            ->name(
                'property-visit-reports.document'
            );


        /*
         * Private site image
         */
        Route::get(
            '/{leadUuid}/site-images/{index}',
            [
                PropertyVisitReportController::class,
                'siteImage'
            ]
        )
            ->whereUuid('leadUuid')
            ->whereNumber('index')
            ->name(
                'property-visit-reports.site-image'
            );


        /*
         * Get by Lead UUID
         */
        Route::get(
            '/{leadUuid}',
            [
                PropertyVisitReportController::class,
                'show'
            ]
        )
            ->whereUuid('leadUuid');


        /*
         * Update by Lead UUID
         */
        Route::put(
            '/{leadUuid}',
            [
                PropertyVisitReportController::class,
                'update'
            ]
        )
            ->whereUuid('leadUuid');


        Route::patch(
            '/{leadUuid}',
            [
                PropertyVisitReportController::class,
                'update'
            ]
        )
            ->whereUuid('leadUuid');
    });