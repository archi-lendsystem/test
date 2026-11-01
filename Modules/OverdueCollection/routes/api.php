<?php

use Illuminate\Support\Facades\Route;
use Modules\OverdueCollection\Http\Controllers\OverdueCollectionController;

Route::middleware(['auth:sanctum'])->prefix('v1/overdue-collection')->name('overdue-collection.')->group(function () {
    Route::get('/', [OverdueCollectionController::class, 'index'])
        ->middleware('permission:overdue-collection.show')
        ->name('index');
});
