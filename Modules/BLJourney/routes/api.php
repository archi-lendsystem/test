<?php

use Illuminate\Support\Facades\Route;
use Modules\BLJourney\Http\Controllers\BLJourneyController;
use Modules\BLJourney\Http\Controllers\BLJourneyConfigurationController;

Route::middleware([
    'auth:sanctum',
    'user.status',
])
    ->prefix('v1/bl')
    ->group(function () {
        Route::prefix('journeys')
            ->controller(BLJourneyController::class)
            ->group(function () {
                Route::get(
                    '/{lead_uuid}',
                    'show'
                )
                    ->middleware('permission:bl-journey.show')
                    ->whereUuid('lead_uuid')
                    ->name('bljourney.show');
            });

        Route::get(
            'leads/{lead_uuid}/journey',
            [BLJourneyConfigurationController::class, 'lead']
        )
            ->middleware('permission:bl-leads.check')
            ->whereUuid('lead_uuid')
            ->name('blleads.journey');
    });

Route::middleware([
    'auth:sanctum',
    'user.status',
    'permission:bl-lead-creation',
])
    ->prefix('v1/product-configuration')
    ->group(function () {
        Route::get(
            '/journey',
            [BLJourneyConfigurationController::class, 'product']
        )
            ->name('product-configuration.journey');
    });