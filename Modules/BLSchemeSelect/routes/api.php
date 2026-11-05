<?php

use Illuminate\Support\Facades\Route;
use Modules\BLSchemeSelect\Http\Controllers\BLSchemeSelectController;



Route::middleware(['auth:sanctum'])
    ->prefix('v1/bl-scheme-selects')
    ->name('bl-scheme-selects.')
    ->group(function () {



           Route::get(
            '/schemes',
            [BLSchemeSelectController::class, 'availableSchemes']
        )->name('available-schemes');

      
          Route::post(
            '/leads/{lead_uuid}/select',
            [BLSchemeSelectController::class, 'selectScheme']
        )->name('select');

     
        Route::get(
            '/leads/{lead_uuid}/selected',
            [BLSchemeSelectController::class, 'selectedScheme']
        )->name('selected');

        Route::delete(
            '/leads/{lead_uuid}/selected',
            [BLSchemeSelectController::class, 'removeSelection']
        )->name('remove-selection');
    });
