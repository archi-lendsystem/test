<?php

$environment = strtolower(
    (string) env(
        'CRIF_ENVIRONMENT',
        'uat'
    )
);

if (
    !in_array(
        $environment,
        [
            'uat',
            'production',
        ],
        true
    )
) {
    $environment = 'uat';
}

$isProduction = $environment === 'production';

return [
    'name' => 'Crif',

    /*
    |--------------------------------------------------------------------------
    | Active CRIF environment
    |--------------------------------------------------------------------------
    |
    | Supported values:
    | - uat
    | - production
    |
    */

    'environment' => $environment,

    /*
    |--------------------------------------------------------------------------
    | CRIF API configuration
    |--------------------------------------------------------------------------
    */

    'api' => [
        'base_url' => $isProduction
            ? env('CRIF_PRODUCTION_BASE_URL')
            : env('CRIF_UAT_BASE_URL'),

        'report_endpoint' => $isProduction
            ? env(
                'CRIF_PRODUCTION_REPORT_ENDPOINT',
                '/api/v1/verify/report-c'
            )
            : env(
                'CRIF_UAT_REPORT_ENDPOINT',
                '/api/v1/verify/report-c'
            ),

        'app_key' => $isProduction
            ? env('CRIF_PRODUCTION_APP_KEY')
            : env('CRIF_UAT_APP_KEY'),

        'secret_key' => $isProduction
            ? env('CRIF_PRODUCTION_SECRET_KEY')
            : env('CRIF_UAT_SECRET_KEY'),

        'timeout' => (int) env(
            'CRIF_TIMEOUT',
            30
        ),

        'connect_timeout' => (int) env(
            'CRIF_CONNECT_TIMEOUT',
            10
        ),
    ],

    'consent_message' => env(
        'CRIF_CONSENT_MESSAGE',
        'I consent to the processing of my personal information '
            . 'for credit-bureau verification.'
    ),

    'expose_raw_response' => filter_var(
        env(
            'CRIF_EXPOSE_RAW_RESPONSE',
            false
        ),
        FILTER_VALIDATE_BOOLEAN
    ),
];