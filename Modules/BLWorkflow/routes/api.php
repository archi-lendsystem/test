<?php

use Illuminate\Support\Facades\Route;
use Modules\BLWorkflow\Http\Controllers\BLWorkflowController;
use Modules\BLWorkflow\Http\Controllers\BLDecisionController;

Route::middleware([
    'auth:sanctum',
    'user.status',

])->prefix('v1')->group(function () {

    Route::prefix('bl/workflow')
        ->controller(BLWorkflowController::class)
        ->group(function () {

         

            Route::get('/stages', 'index')
                ->middleware('permission:bl-workflow.stages.show')
                ->name('blworkflow.stages.index');

            Route::get('/stages/{stage}', 'show')
                ->middleware('permission:bl-workflow.stages.show')
                ->name('blworkflow.stages.show');

            Route::get('/stages/{stage}/statuses', 'statuses')
                ->middleware('permission:bl-workflow.stages.show')
                ->name('blworkflow.stages.statuses');


        

            Route::get('/transitions', 'transitions')
                ->middleware('permission:bl-workflow.transitions')
                ->name('blworkflow.transitions');

            Route::get('/permissions/{stage}', 'permissions')
                ->middleware('permission:bl-workflow.permissions')
                ->name('blworkflow.permissions');


          

            Route::get('/lead/{lead_uuid}/current', 'current')
                ->middleware('permission:bl-workflow.lead.current')
                ->name('blworkflow.lead.current');

            Route::post('/lead/{lead_uuid}/initiate', 'initiate')
                ->middleware('permission:bl-workflow.lead.initiate')
                ->name('blworkflow.lead.initiate');

            Route::post('/lead/{lead_uuid}/change-status', 'changeStatus')
                ->middleware('permission:bl-workflow.lead.change-status')
                ->name('blworkflow.lead.change-status');

            Route::post('/lead/{lead_uuid}/move-next', 'moveNext')
                ->middleware('permission:bl-workflow.lead.move-next')
                ->name('blworkflow.lead.move-next');

            Route::post('/lead/{lead_uuid}/move-previous', 'movePrevious')
                ->middleware('permission:bl-workflow.lead.move-previous')
                ->name('blworkflow.lead.move-previous');

        });

});

Route::prefix('bl-decisions')
    ->middleware('auth:sanctum')
    ->group(function () {
        Route::get('/{leadUuid}', [
            BLDecisionController::class,
            'state',
        ]);

        Route::post('/{leadUuid}/approve', [
            BLDecisionController::class,
            'approve',
        ]);

        Route::post('/{leadUuid}/reject', [
            BLDecisionController::class,
            'reject',
        ]);

        Route::post('/{leadUuid}/reopen', [
            BLDecisionController::class,
            'reopen',
        ]);
    });
