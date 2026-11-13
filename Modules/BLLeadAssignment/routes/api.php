<?php

use Illuminate\Support\Facades\Route;
use Modules\BLLeadAssignment\Http\Controllers\BLLeadAssignmentController;

Route::prefix('v1/bl/lead-assignment')
    ->middleware([
        'auth:sanctum',
        'user.status',
    ])
    ->group(function () {

       

        Route::get(
            '/',
            [BLLeadAssignmentController::class, 'index']
        )
        ->middleware('permission:bl-lead-assignment.show')
        ->name('blleadassignment.index');


        Route::get(
            '/{id}',
            [BLLeadAssignmentController::class, 'show']
        )
        ->middleware('permission:bl-lead-assignment.show')
        ->whereNumber('id')
        ->name('blleadassignment.show');


        

        Route::post(
            '/',
            [BLLeadAssignmentController::class, 'store']
        )
        ->middleware('permission:bl-lead-assignment.store')
        ->name('blleadassignment.store');


        Route::get(
            'leads/{lead_uuid}/my-assignment',
            [BLLeadAssignmentController::class, 'myAssignmentView']
        )
        ->middleware('permission:bl-lead-assignment.my-assignment')
        ->name('blleadassignment.my-assignment');


       

        Route::get(
            '/check-permission/{lead_uuid}',
            [BLLeadAssignmentController::class, 'checkPermission']
        )
        ->middleware('permission:bl-lead-assignment.check-permission')
        ->whereUuid('lead_uuid')
        ->name('blleadassignment.check-permission');


        

        Route::get(
            '/eligible-users/{lead_uuid}',
            [BLLeadAssignmentController::class, 'eligibleUsersForAssignment']
        )
        ->middleware('permission:bl-lead-assignment.eligible-users')
        ->whereUuid('lead_uuid')
        ->name('blleadassignment.eligible-users');


      

        Route::patch(
            '/{id}/complete',
            [BLLeadAssignmentController::class, 'complete']
        )
        ->middleware('permission:bl-lead-assignment.complete')
        ->whereNumber('id')
        ->name('blleadassignment.complete');


        Route::patch(
            '/{id}/cancel',
            [BLLeadAssignmentController::class, 'cancel']
        )
        ->middleware('permission:bl-lead-assignment.complete')
        ->whereNumber('id')
        ->name('blleadassignment.cancel');

    });
