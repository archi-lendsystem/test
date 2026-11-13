<?php

use Illuminate\Support\Facades\Route;

// Route::get('/', function () {
//     return view('welcome');
// });


Route::get('/test', function () {
    return response()->json([
        'status' => true,
        'message' => 'API working'
    ]);
});


// use Illuminate\Support\Facades\Route;

Route::get('/debug-headers', function () {
    $file = null;
    $line = null;

    $sent = headers_sent($file, $line);

    return response()->json([
        'headers_sent' => $sent,
        'file' => $file,
        'line' => $line,
    ]);
});