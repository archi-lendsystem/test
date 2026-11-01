<?php

use Illuminate\Support\Facades\Route;
use Modules\Pan\Http\Controllers\PanController;

/*
 * Add your existing auth/permission middleware around these routes if needed.
 */
Route::post(
    '/bl-leads/{leadUuid}/pan-verification',
    [PanController::class, 'verify']
)->whereUuid('leadUuid');

Route::post(
    '/bl-leads/{leadUuid}/pan-verifications/lookup',
    [PanController::class, 'lookup']
)->whereUuid('leadUuid');

Route::get(
    '/bl-leads/{leadUuid}/pan-verifications/{verificationId}',
    [PanController::class, 'status']
)
    ->whereUuid('leadUuid')
    ->whereNumber('verificationId');