<?php

use Illuminate\Support\Facades\Route;
use Modules\BLInsurance\Http\Controllers\BLInsuranceController;

Route::middleware([
    'auth:sanctum',
    'user.status',
    
])
->prefix('v1/bl/insurance')
->group(function () {

   

    Route::get(
        'rate-table',
        [BLInsuranceController::class, 'rateTable']
    )
    ->middleware('permission:bl-insurance.rate-table')
    ->name('bl.insurance.rate-table');




    Route::post(
        'calculate',
        [BLInsuranceController::class, 'calculate']
    )
    ->middleware('permission:bl-insurance.calculate')
    ->name('bl.insurance.calculate');




    Route::get(
        '/',
        [BLInsuranceController::class, 'index']
    )
    ->middleware('permission:bl-insurance.index')
    ->name('bl.insurance.index');


    Route::post(
        '/',
        [BLInsuranceController::class, 'store']
    )
    ->middleware('permission:bl-insurance.store')
    ->name('bl.insurance.store');



    Route::get(
        '/{id}',
        [BLInsuranceController::class, 'show']
    )
    ->middleware('permission:bl-insurance.show')
    ->name('bl.insurance.show');

});