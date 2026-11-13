
<?php

use Illuminate\Support\Facades\Route;
use Modules\PaydayLoanPartner\Http\Controllers\PaydayLoanPartnerController;

Route::middleware(['auth:sanctum', 'user.status'])->prefix('v1')->group(function () {

    Route::prefix('payday/partners')
        ->controller(PaydayLoanPartnerController::class)
        ->group(function () {
            
            Route::get('/', 'index');
            Route::post('/', 'store');
            Route::get('/{uuid}', 'show');
            Route::put('/{uuid}', 'update');
            Route::patch('/{uuid}/toggle-active', 'toggleActive');
        });
});