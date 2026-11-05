<?php

return [
    'name' => 'Auth',

    'login' => [
        'channel' => env('AUTH_OTP_CHANNEL', 'email'),
    ],

    'dlt' => [
        'template' => env('AUTH_DLT_TEMPLATE'),
    ],
];