<?php

// // Sandbox and production use different hosts (not just a flag) — base_url
// // must follow env unless explicitly overridden.
// $digioEnv = env('DIGIO_ENV', 'sandbox');

// return [
//     'env'            => $digioEnv,
//     'base_url'       => env('DIGIO_BASE_URL', $digioEnv === 'production' ? 'https://api.digio.in' : 'https://ext.digio.in:444'),
//     'client_id'      => env('DIGIO_CLIENT_ID'),
//     'client_secret'  => env('DIGIO_CLIENT_SECRET'),
//     'webhook_secret' => env('DIGIO_WEBHOOK_SECRET'),
//     'timeout'        => env('DIGIO_API_TIMEOUT', 30),
//     'max_retries'    => env('DIGIO_MAX_RETRIES', 3),

//     'endpoints' => [
//         'create_sign_request' => '/v2/client/document/uploadpdf',
//         'get_status'          => '/v2/client/document/',
//         'download_document'   => '/v2/client/document/download/',
//     ],

//     // DigiCollect / eNACH mandate endpoints. Digio's docs portal gates the
//     // exact request/response schema behind a JS code-sample widget that
//     // could not be scraped — paths below are best-effort from published
//     // field names (ENA id, UMRN) and should be confirmed against the
//     // logged-in Digio dashboard docs before go-live.
//     'nach_endpoints' => [
//         'create_mandate'  => '/client/nach/mandate/create',
//         'mandate_status'  => '/client/nach/mandate/',
//         'cancel_mandate'  => '/client/nach/mandate/cancel/',
//     ],
// ];


$digioEnv = strtolower(trim((string) env('DIGIO_ENV', 'sandbox')));

// Safety fallback:
// Unknown/mistyped environments must never accidentally point to production.
if (! in_array($digioEnv, ['sandbox', 'production'], true)) {
    $digioEnv = 'sandbox';
}

return [

    /*
    |--------------------------------------------------------------------------
    | Digio Environment
    |--------------------------------------------------------------------------
    */

    'env' => $digioEnv,

    /*
    |--------------------------------------------------------------------------
    | Digio Base URL
    |--------------------------------------------------------------------------
    |
    | Sandbox:
    | https://ext.digio.in:444
    |
    | Production:
    | https://api.digio.in
    |
    */

    'base_url' => rtrim(
        (string) env(
            'DIGIO_BASE_URL',
            $digioEnv === 'production'
                ? 'https://api.digio.in'
                : 'https://ext.digio.in:444'
        ),
        '/'
    ),

    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    */

    'client_id' => env('DIGIO_CLIENT_ID'),

    'client_secret' => env('DIGIO_CLIENT_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Webhook
    |--------------------------------------------------------------------------
    */

    'webhook_secret' => env('DIGIO_WEBHOOK_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | HTTP Settings
    |--------------------------------------------------------------------------
    */

    'timeout' => max(
        1,
        (int) env('DIGIO_API_TIMEOUT', 30)
    ),

    'connect_timeout' => max(
        1,
        (int) env('DIGIO_CONNECT_TIMEOUT', 10)
    ),

    'max_retries' => max(
        0,
        (int) env('DIGIO_MAX_RETRIES', 3)
    ),

    /*
    |--------------------------------------------------------------------------
    | DigiSign Defaults
    |--------------------------------------------------------------------------
    */

    'sign_request_expiry_days' => max(
        1,
        (int) env('DIGIO_SIGN_REQUEST_EXPIRY_DAYS', 10)
    ),

    'default_display_on_page' => (string) env(
        'DIGIO_DISPLAY_ON_PAGE',
        'first'
    ),

    'notify_signers' => filter_var(
        env('DIGIO_NOTIFY_SIGNERS', true),
        FILTER_VALIDATE_BOOL
    ),

    'send_sign_link' => filter_var(
        env('DIGIO_SEND_SIGN_LINK', true),
        FILTER_VALIDATE_BOOL
    ),

    'generate_access_token' => filter_var(
        env('DIGIO_GENERATE_ACCESS_TOKEN', true),
        FILTER_VALIDATE_BOOL
    ),

    /*
    |--------------------------------------------------------------------------
    | Storage
    |--------------------------------------------------------------------------
    |
    | Signed documents must not be stored on a publicly accessible disk.
    |
    */

    'storage_disk' => (string) env(
        'DIGIO_STORAGE_DISK',
        'private'
    ),

    /*
    |--------------------------------------------------------------------------
    | Security
    |--------------------------------------------------------------------------
    |
    | Sanction and BL Reports will be approved eSign entry points.
    | Generic arbitrary PDF signing remains disabled.
    |
    */

    'allow_generic_initiation' => filter_var(
        env('DIGIO_ALLOW_GENERIC_INITIATION', false),
        FILTER_VALIDATE_BOOL
    ),

    /*
    |--------------------------------------------------------------------------
    | DigiSign API Endpoints
    |--------------------------------------------------------------------------
    */

    'endpoints' => [

        'create_sign_request' =>
            '/v2/client/document/uploadpdf',

        'get_document' =>
            '/v2/client/document/',

        'download_document' =>
            '/v2/client/document/download',
    ],

    /*
    |--------------------------------------------------------------------------
    | Existing NACH Configuration
    |--------------------------------------------------------------------------
    |
    | NACH is outside the current DigiSign scope.
    | Existing configuration is retained and not modified.
    |
    */

    'nach_endpoints' => [

        'create_mandate' =>
            '/client/nach/mandate/create',

        'mandate_status' =>
            '/client/nach/mandate/',

        'cancel_mandate' =>
            '/client/nach/mandate/cancel/',
    ],
];