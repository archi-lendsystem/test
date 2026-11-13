<?php

use Illuminate\Support\Facades\Route;
use Modules\CrifSubmission\Http\Controllers\CrifSubmissionController;



// middleware(['auth:sanctum'])-> 
Route::prefix('v1')
    ->group(function () {

      
        Route::get('crif-submissions', [CrifSubmissionController::class, 'index'])
            ->name('crif-submissions.index');

      
        Route::post('crif-submissions', [CrifSubmissionController::class, 'store'])
            ->name('crif-submissions.store');

       
        Route::get('crif-submissions/{uuid}', [CrifSubmissionController::class, 'show'])
            ->name('crif-submissions.show');

 
        Route::put('crif-submissions/{uuid}', [CrifSubmissionController::class, 'update'])
            ->name('crif-submissions.update');

    
        Route::patch('crif-submissions/{uuid}', [CrifSubmissionController::class, 'update'])
            ->name('crif-submissions.patch');

 
        Route::delete('crif-submissions/{uuid}', [CrifSubmissionController::class, 'destroy'])
            ->name('crif-submissions.destroy');
    });