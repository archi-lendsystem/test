<?php

use Illuminate\Support\Facades\Route;
use Modules\BLBankDetails\Http\Controllers\BLBankDetailsController;

Route::middleware([
    'auth:sanctum',
    'user.status',
])->prefix('v1/bl/bank/details')->group(function () {



    Route::post(
        '/store',
        [BLBankDetailsController::class, 'store']
    )
    ->middleware('permission:bl-lead-creation')
        ->name('bl.bankdetails.store');


    Route::get(
        '/{lead_uuid}/{applicant_id}',
        [BLBankDetailsController::class, 'show']
    )
    ->middleware('permission:bl-lead-creation')
        ->name('bl.bank-details.show');

        Route::get(
    '/{lead_uuid}/{applicant_id}/{id}/statement/view',
    [BLBankDetailsController::class, 'viewStatement']
)
->middleware('permission:bl-lead-creation')
->name('bl.bank-details.statement.view');


Route::get(
    '/{lead_uuid}/{applicant_id}/{id}/statement/download',
    [BLBankDetailsController::class, 'downloadStatement']
)
->middleware('permission:bl-lead-creation')
->name('bl.bank-details.statement.download');

    Route::put(
        '/{lead_uuid}/{applicant_id}/{id}',
        [BLBankDetailsController::class, 'update']
    )
    ->middleware('permission:bl-lead-creation')
        ->name('bl.bank-details.update');


    Route::delete(
        '/{lead_uuid}/{applicant_id}/{id}',
        [BLBankDetailsController::class, 'destroy']
    )
    ->middleware('permission:bl-lead-creation')
        ->name('bl.bank-details.destroy');

});
