<?php

return [

    'name' => 'Transunion',

    /*
    |--------------------------------------------------------------------------
    | Base URL
    |--------------------------------------------------------------------------
    */
    'base_url' => env(
        'TRANSUNION_BASE_URL',
        'https://api.thelendsystem.com'
    ),

    /*
    |--------------------------------------------------------------------------
    | Credentials
    |--------------------------------------------------------------------------
    |
    | The same config keys are used for whichever environment
    | is active.
    |
    */
    'app_key' => env(
        'TRANSUNION_APP_KEY'
    ),

    'secret_key' => env(
        'TRANSUNION_SECRET_KEY'
    ),

    /*
    |--------------------------------------------------------------------------
    | Environment
    |--------------------------------------------------------------------------
    |
    | uat | production
    |
    | Controlled entirely from .env.
    |
    */
    'environment' => strtolower(
        trim(
            (string) env(
                'TRANSUNION_ENVIRONMENT',
                ''
            )
        )
    ),

    /*
    |--------------------------------------------------------------------------
    | Vendor API Paths
    |--------------------------------------------------------------------------
    */

    'report_b_path' =>
        '/api/v1/verify/report-b',

    'responses_path' =>
        '/api/v1/responses',

    /*
    |--------------------------------------------------------------------------
    | HTTP Settings
    |--------------------------------------------------------------------------
    */

    'timeout' => (int) env(
        'TRANSUNION_TIMEOUT',
        60
    ),

    'connect_timeout' => (int) env(
        'TRANSUNION_CONNECT_TIMEOUT',
        10
    ),

];