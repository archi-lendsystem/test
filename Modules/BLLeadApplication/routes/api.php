<?php

use Illuminate\Support\Facades\Route;
use Modules\BLLeadApplication\Http\Controllers\BLLeadApplicationController;

Route::middleware([
    'auth:sanctum',
    'user.status',
  
])->prefix('v1')->group(function () {

   

    Route::get(
        '/bl/leadapplications/{lead_uuid}',
        [BLLeadApplicationController::class, 'show']
    )
    ->middleware('permission:bl-lead-creation')
    ->name('blleadapplication.show');

});
