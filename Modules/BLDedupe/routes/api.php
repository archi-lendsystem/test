<?php

use Illuminate\Support\Facades\Route;
use Modules\BLDedupe\Http\Controllers\BLDedupeController;

Route::middleware([
    'auth:sanctum',
    'user.status',
  
])->prefix('v1/bl/dedupe')->group(function () {

    /*
    |--------------------------------------------------------------------------
    | BL Dedupe
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/{lead_uuid}',
        [BLDedupeController::class, 'show']
    )
->middleware('permission:bl-dedupe.show')
    ->name('bl.dedupe.show');

});
