<?php

use Illuminate\Support\Facades\Route;
use Modules\BLDocumentCheckList\Http\Controllers\BLDocumentCheckListController;
use Modules\BLDocumentCheckList\Http\Controllers\BLDocumentController;
use Modules\BLDocumentCheckList\Http\Controllers\BLDocumentGroupController;

Route::middleware(['auth:sanctum'])->prefix('v1/bl')->group(function () {
    Route::get(
        'documentchecklists/{checklist_uuid}/groups',
        [BLDocumentGroupController::class, 'byChecklist']
    )
        ->middleware('permission:bl-document-group.by-checklist')->name('bldocumentchecklist.groups');

    Route::post(
        'documentchecklists/{checklist_uuid}/groups',
        [BLDocumentGroupController::class, 'storeByChecklist']
    )
        ->middleware('permission:bl-document-group.store-by-checklist')->name('bldocumentchecklist.groups.store');

    Route::get(
        'documentchecklists/{checklist_uuid}/documents',
        [BLDocumentController::class, 'byChecklist']
    )
        ->middleware('permission:bl-document.by-checklist')->name('bldocumentchecklist.documents');

    Route::get(
        'documentgroups/{group_uuid}/documents',
        [BLDocumentController::class, 'byGroup']
    )
        ->middleware('permission:bl-document.by-group')->name('bldocumentgroup.documents');

    Route::post(
        'documentgroups/{group_uuid}/documents',
        [BLDocumentController::class, 'storeByGroup']
    )
        ->middleware('permission:bl-document.store-by-group')->name('bldocumentgroup.documents.store');

    /*
    |--------------------------------------------------------------------------
    | Standard CRUD APIs
    |--------------------------------------------------------------------------
    */

    Route::apiResource('documentchecklists', BLDocumentCheckListController::class)
        ->parameters(['documentchecklists' => 'checklist_uuid'])
        ->middleware('permission:bl-document-checklist.crud')
        ->names('bldocumentchecklist');

    Route::apiResource('documentgroups', BLDocumentGroupController::class)
        ->parameters(['documentgroups' => 'group_uuid'])
        ->middleware('permission:bl-document-group.crud')
        ->names('bldocumentgroup');

    Route::apiResource('documents', BLDocumentController::class)
        ->parameters(['documents' => 'document_uuid'])
        ->middleware('permission:bl-document.crud')
        ->names('bldocument');
});
