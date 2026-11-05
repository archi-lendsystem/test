<?php

use Illuminate\Support\Facades\Route;
use Modules\BLLeads\Http\Controllers\BLLeadsController;
use Modules\BLLeads\Http\Controllers\BLLeadAccessController;

Route::middleware([
    'auth:sanctum',
    'user.status'

])->prefix('v1/bl')->group(function () {

    Route::get('leads/{lead_uuid}/edit-access', [BLLeadAccessController::class, 'check'])
    ->middleware('permission:bl-leads.check')
        ->name('blleads.edit-access');

    Route::get(
        'leads',
        [BLLeadsController::class, 'index']
    )
    ->middleware('permission:bl-leads.check')
    ->name('blleads.index');

});