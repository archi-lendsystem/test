<?php

use Illuminate\Support\Facades\Route;
use Modules\BLSanctionCondition\Http\Controllers\BLSanctionConditionController;

Route::middleware(['auth:sanctum'])->prefix('v1/bl')->group(function () {

    Route::get('{leadUuid}/page-data', [BLSanctionConditionController::class, 'getPageData'])
        ->middleware('permission:bl-sanction-condition.getpagedata');

    Route::post('{leadUuid}/custom-condition', [BLSanctionConditionController::class, 'addCustomCondition'])
        ->middleware('permission:bl-sanction-condition.addcustomcondition');

    Route::post('{leadUuid}/save', [BLSanctionConditionController::class, 'saveSelectedConditions'])
        ->middleware('permission:bl-sanction-condition.saveselectedconditions');

});
