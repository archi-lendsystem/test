<?php

use Illuminate\Support\Facades\Route;
use Modules\BLDocumentUpload\Http\Controllers\BLDocumentUploadController;




/*
|--------------------------------------------------------------------------
| Signed document view route
|--------------------------------------------------------------------------
*/

Route::prefix('v1/bl')->group(function () {
    Route::get(
        'documentuploads/{upload_uuid}/view',
        [BLDocumentUploadController::class, 'viewDocument']
    )
        ->whereUuid('upload_uuid')
        ->middleware('signed:relative')
        ->name('bldocumentuploads.view');
});

/*
|--------------------------------------------------------------------------
| Authenticated document upload routes
|--------------------------------------------------------------------------
*/

Route::middleware([
    'auth:sanctum',
    'user.status',
])->prefix('v1/bl')->group(function () {
    Route::post(
        'document/uploads/submit',
        [BLDocumentUploadController::class, 'finalValidation']
    )
        ->middleware('permission:bl-lead-creation')
        ->name('bldocumentupload.submit');

    Route::get(
        'documentuploads/lead/{lead_id}',
        [BLDocumentUploadController::class, 'byLead']
    )
        ->middleware('permission:bl-lead-creation')
        ->name('bldocumentupload.by-lead');

    Route::get(
        'documentuploads',
        [BLDocumentUploadController::class, 'index']
    )
        ->middleware('permission:bl-lead-creation')
        ->name('bldocumentupload.index');

    Route::post(
        'documentuploads',
        [BLDocumentUploadController::class, 'store']
    )
        ->middleware('permission:bl-lead-creation')
        ->name('bldocumentupload.store');

    Route::get(
        'documentuploads/{upload_uuid}',
        [BLDocumentUploadController::class, 'show']
    )
        ->whereUuid('upload_uuid')
        ->middleware('permission:bl-lead-creation')
        ->name('bldocumentupload.show');

    Route::put(
        'documentuploads/{upload_uuid}',
        [BLDocumentUploadController::class, 'update']
    )
        ->whereUuid('upload_uuid')
        ->middleware('permission:bl-lead-creation')
        ->name('bldocumentupload.update');

    Route::patch(
        'documentuploads/{upload_uuid}',
        [BLDocumentUploadController::class, 'update']
    )
        ->whereUuid('upload_uuid')
        ->middleware('permission:bl-lead-creation')
        ->name('bldocumentupload.patch');

    Route::delete(
        'documentuploads/{upload_uuid}',
        [BLDocumentUploadController::class, 'destroy']
    )
        ->whereUuid('upload_uuid')
        ->middleware('permission:bl-lead-creation')
        ->name('bldocumentupload.destroy');
});


