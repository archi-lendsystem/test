<?php

use Illuminate\Support\Facades\Route;
use Modules\BLIMDDetailsVerification\Http\Controllers\BLIMDDetailsVerificationController;

// Route::middleware([
//     'auth:sanctum',
//     'user.status',
// ])->prefix('v1/bl')->group(function () {


//     Route::post(
//         '/imd-verification/{lead_uuid}/{applicant_id}',
//         [BLIMDDetailsVerificationController::class, 'store']
//     )
//      ->middleware('permission:bl-imd-verification.store')
//     ->name('blimdverification.store');


//     Route::get(
//         '/imd-verification/{lead_uuid}',
//         [BLIMDDetailsVerificationController::class, 'show']
//     )
//     ->middleware('permission:bl-imd-verification.show')
//     ->name('blimdverification.show');


//     Route::put(
//         '/imd-verification/{lead_uuid}/{applicant_id}',
//         [BLIMDDetailsVerificationController::class, 'update']
//     )
//       ->middleware('permission:bl-imd-verification.store')
//     ->name('blimdverification.update');

// });
