<?php

return [
    'paths' => [
        'api/*',
        'sanctum/csrf-cookie',
    ],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'http://localhost:5173',
        'https://stg-sabrimala.thelendsystem.com',
        'http://localhost:5173',
        ' http://10.200.21.200:5173/',
        'https://2klj7dtf-5173.inc1.devtunnels.ms',
        'https://l1q6c0zj-5173.inc1.devtunnels.ms',
        'https://m2bm2k6x-5173.inc1.devtunnels.ms',
        'https://localhost'
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 86400,

    'supports_credentials' => true,
];