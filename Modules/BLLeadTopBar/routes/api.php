<?php

use Illuminate\Support\Facades\Route;
use Modules\BLLeadTopBar\Http\Controllers\BLLeadTopBarController;

Route::middleware('auth:sanctum')
    ->prefix('v1/bl')
    ->name('bl/lead/top/bar.')
    ->group(function () {
        Route::get('/lead/top/bar/{uuid}', [BLLeadTopBarController::class, 'show'])
            ->whereUuid('uuid')
            ->name('show');
    });
