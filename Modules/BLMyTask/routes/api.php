<?php

use Illuminate\Support\Facades\Route;
use Modules\BLMyTask\Http\Controllers\BLMyTaskController;

Route::middleware([
    'auth:sanctum',
    'user.status',

])
    ->prefix('v1/bl')
    ->group(function () {


        Route::get(
            'mytasks',
            [BLMyTaskController::class, 'index']
        )
            ->middleware('permission:bl-my-task.index')
            ->name('blmytasks.index');

    });
