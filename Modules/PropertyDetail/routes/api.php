<?php

use Illuminate\Support\Facades\Route;
use Modules\PropertyDetail\Http\Controllers\PropertyDetailController;

Route::middleware('auth:sanctum')
    ->prefix('v1/property')
    ->group(function () {
        // Only LAP (product_id = 1). Names come from BL applicant details.
        Route::get('/eligible-owners/{lead_uuid}', [PropertyDetailController::class, 'getEligiblePropertyOwners']);

        // POST body contains lead_uuid and pd_property_owner applicant IDs.
        // Route::post('/details', [PropertyDetailController::class, 'store']);
        Route::post('/details/submit', [PropertyDetailController::class, 'finalValidation']);

        // property_id is no longer part of this module.
        Route::get('/details/{lead_uuid}', [PropertyDetailController::class, 'show']);
        Route::put('/details/{lead_uuid}', [PropertyDetailController::class, 'update']);
        Route::get('/details/{lead_uuid}/document', [PropertyDetailController::class, 'document']);
    });
