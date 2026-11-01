<?php

use Illuminate\Support\Facades\Route;
use Modules\PropertyDetail\Http\Controllers\PropertyDetailController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('propertydetails', PropertyDetailController::class)->names('propertydetail');
});
