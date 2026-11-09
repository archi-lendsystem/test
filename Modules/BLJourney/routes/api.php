<?php

use Illuminate\Support\Facades\Route;
use Modules\BLJourney\Http\Controllers\BLJourneyController;

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

});
