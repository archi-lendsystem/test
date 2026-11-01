<?php

use Illuminate\Support\Facades\Route;
use Modules\BLLeadCreation\Http\Controllers\BLLeadCreationController;

Route::middleware(['auth:sanctum', 'user.status'])->prefix('v1/bl')->group(function () {

  Route::get(
        'lead/creation/assigned-branches',
        [BLLeadCreationController::class, 'assignedBranches']
    )->middleware('permission:bl-lead-creation');
    
    Route::apiResource('lead/creation', BLLeadCreationController::class)
        ->middleware('permission:bl-lead-creation')->names('blleadcreation');
        

    Route::get('lead/creation/{lead_uuid}', [BLLeadCreationController::class, 'show'])
        ->middleware('permission:bl-lead-creation');

    Route::post('lead/creation/send/otp', [BLLeadCreationController::class, 'sendOtp'])
        ->middleware('permission:bl-lead-creation');

    Route::post('lead/creation/verify/otp', [BLLeadCreationController::class, 'verifyOtpAndStore'])
        ->middleware('permission:bl-lead-creation');



Route::patch(
    'lead/creation/{lead_uuid}/editable-details',
    [BLLeadCreationController::class, 'updateEditableDetails']
)->middleware('permission:bl-lead-creation')
  ->name('blleadcreation.editable-details.update');
});
