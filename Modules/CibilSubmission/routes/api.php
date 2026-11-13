<?php

use Illuminate\Support\Facades\Route;
use Modules\CibilSubmission\Http\Controllers\CibilSubmissionController;




Route::middleware(['auth:sanctum'])->prefix('v1')
    ->group(function () {

       

       
        Route::get('cibil-submissions', [CibilSubmissionController::class, 'index'])
            ->name('cibil-submissions.index');

   
        Route::post('cibil-submissions', [CibilSubmissionController::class, 'store'])
            ->name('cibil-submissions.store');

   
        Route::get('cibil-submissions/{uuid}', [CibilSubmissionController::class, 'show'])
            ->name('cibil-submissions.show');

    
        Route::put('cibil-submissions/{uuid}', [CibilSubmissionController::class, 'update'])
            ->name('cibil-submissions.update');

        Route::patch('cibil-submissions/{uuid}', [CibilSubmissionController::class, 'update'])
            ->name('cibil-submissions.patch');

      
        Route::delete('cibil-submissions/{uuid}', [CibilSubmissionController::class, 'destroy'])
            ->name('cibil-submissions.destroy');

  
        Route::patch('cibil-submissions/{uuid}/mark-valid', [CibilSubmissionController::class, 'markValid'])
            ->name('cibil-submissions.mark-valid');
    });