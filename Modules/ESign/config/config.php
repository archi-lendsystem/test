<?php

use Modules\ESign\Services\ConfigurableHttpESignProvider;

return [

    'name' => 'ESign',

    'enabled' => (bool) env(
        'ESIGN_ENABLED',
        true
    ),

    'provider_class' => ConfigurableHttpESignProvider::class,

    /*
    |--------------------------------------------------------------------------
    | Provider
    |--------------------------------------------------------------------------
    */

    'provider' => [

        'name' => env(
            'ESIGN_PROVIDER_NAME',
            'thelendsystem'
        ),

        'base_url' => rtrim(
            (string) env(
                'ESIGN_PROVIDER_BASE_URL',
                'https://api.thelendsystem.com'
            ),
            '/'
        ),

        /*
        | App Key + Secret Key headers
        */

        'auth_mode' => 'headers',

        'app_key' => env(
            'ESIGN_PROVIDER_APP_KEY'
        ),

        'secret_key' => env(
            'ESIGN_PROVIDER_SECRET_KEY'
        ),

        'app_key_header' => 'X-App-Key',

        'secret_key_header' => 'X-Secret-Key',

        /*
        | eSign (esign-generate/short-url/get-doc) is documented as
        | "Production only — not available in UAT" for every step, so
        | this always defaults to production regardless of what other
        | Lendsystem products (DigiLocker/PAN/Liveness/...) are set to.
        | Omitting X-Environment defaults to uat on the vendor's side,
        | which rejects a production-scoped secret key outright.
        */
        'environment' => env(
            'ESIGN_PROVIDER_ENVIRONMENT',
            'production'
        ),

        'environment_header' => 'X-Environment',

        /*
        | Actual provider endpoints
        */

        'endpoints' => [

            'generate' => env(
                'ESIGN_PROVIDER_GENERATE_ENDPOINT',
                '/api/v1/verify/esign-generate'
            ),

            'short_url' => env(
                'ESIGN_PROVIDER_SHORT_URL_ENDPOINT',
                '/api/v1/verify/esign-generate-short-url'
            ),

            'get_doc' => env(
                'ESIGN_PROVIDER_GET_DOC_ENDPOINT',
                '/api/v1/verify/esign-get-doc'
            ),
        ],

        /*
        | Where to read fields from the vendor's response envelope
        | ({success,message,status_code,request_id,timestamp,data:{...}}
        | — same shape as DigiLocker/Liveness/Geocoding).
        */
        'response_paths' => [

            'request_id' => 'data.request_id',

            'reference_key' => 'data.ReferenceKey',

            'signing_url' => 'data.esign_url',

            'upload_url' => 'data.upload_url',

            'status' => 'data.status',

            'download_url' => 'data.download_url',
        ],

        /*
        | Step 1's "status" (eg. "created") and Step 3's per-signer
        | "state" (eg. "signed"/"notsigned") both flow through the same
        | mappedStatus() lookup.
        */
        'status_map' => [

            'created' => 'pending',

            'pending' => 'pending',

            'notsigned' => 'pending',

            'not_signed' => 'pending',

            'signed' => 'signed',

            'failed' => 'failed',

            'expired' => 'expired',

            'cancelled' => 'cancelled',
        ],

        /*
        | HTTP
        */

        'timeout' => 30,

        'connect_timeout' => 10,

        'retry_times' => 2,

        'retry_sleep_ms' => 250,

        'verify_tls' => true,

        /*
        | Webhook verification
        */

        'webhook' => [

            // Public callback URL to register in the eSign provider portal.
            'public_url' => env(
                'ESIGN_WEBHOOK_URL',
                rtrim((string) env('APP_URL'), '/').'/api/esign/webhook'
            ),

            // hmac (recommended), token, or none (only for providers with no verification support).
            'verification_mode' => env(
                'ESIGN_WEBHOOK_VERIFICATION_MODE',
                'hmac'
            ),

            'secret' => env(
                'ESIGN_WEBHOOK_SECRET'
            ),

            'signature_header' =>
                env('ESIGN_WEBHOOK_SIGNATURE_HEADER', 'X-ESign-Signature'),

            'timestamp_header' =>
                env('ESIGN_WEBHOOK_TIMESTAMP_HEADER', 'X-ESign-Timestamp'),

            'token_header' =>
                env('ESIGN_WEBHOOK_TOKEN_HEADER', 'X-ESign-Webhook-Token'),

            'algorithm' => env(
                'ESIGN_WEBHOOK_ALGORITHM',
                'sha256'
            ),

            'tolerance_seconds' => env(
                'ESIGN_WEBHOOK_TOLERANCE',
                300
            ),
        ],

        // Override these paths if the provider's webhook payload uses a different envelope.
        'webhook_paths' => [
            'event_id' => env('ESIGN_WEBHOOK_EVENT_ID_PATH', 'event_id'),
            'reference_key' => env('ESIGN_WEBHOOK_REFERENCE_KEY_PATH', 'data.ReferenceKey'),
            'request_id' => env('ESIGN_WEBHOOK_REQUEST_ID_PATH', 'data.request_id'),
            'document_id' => env('ESIGN_WEBHOOK_DOCUMENT_ID_PATH', 'data.docId'),
            'status' => env('ESIGN_WEBHOOK_STATUS_PATH', 'data.state'),
            'occurred_at' => env('ESIGN_WEBHOOK_OCCURRED_AT_PATH', 'timestamp'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Templates
    |--------------------------------------------------------------------------
    */

    'template_only' => (bool) env(
        'ESIGN_TEMPLATE_ONLY',
        true
    ),

    // 'templates' => [

    //     'borrower' => env(
    //         'ESIGN_BORROWER_TEMPLATE_ID'
    //     ),

    //     'lender' => env(
    //         'ESIGN_LENDER_TEMPLATE_ID'
    //     ),
    // ],

    /*
     * The provider API supplied for this project does not accept x/y signature
     * coordinates. Configure two provider templates instead:
     * - borrower template: signature field on the LEFT
     * - lender template: signature field on the RIGHT
     */
    'templates' => [
        'borrower' => env('ESIGN_BORROWER_TEMPLATE_ID'),
        'lender' => env('ESIGN_LENDER_TEMPLATE_ID'),
    ],

/*
|--------------------------------------------------------------------------
| Lender signer
|--------------------------------------------------------------------------
*/

'lender' => [

    'name' => env(
        'ESIGN_LENDER_NAME'
    ),

    'email' => env(
        'ESIGN_LENDER_EMAIL'
    ),

    'mobile' => env(
        'ESIGN_LENDER_MOBILE'
    ),
    
     'location' => env(
        'ESIGN_LENDER_LOCATION',
        'India'
    ),

    'identifier' => env(
        'ESIGN_LENDER_IDENTIFIER',
        'FRIENDLY-SALARY-LENDER'
    ),
],

    /*
    |--------------------------------------------------------------------------
    | Link delivery
    |--------------------------------------------------------------------------
    */

    'notifications' => [
        // Step 2 (esign-generate-short-url) is called automatically for lender.
        'lender_sms' => (bool) env('ESIGN_LENDER_SMS_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage
    |--------------------------------------------------------------------------
    */

    'storage' => [

        'source_disk' => env(
            'ESIGN_SOURCE_DISK',
            'local'
        ),

        'signed_disk' => env(
            'ESIGN_SIGNED_DISK',
            'local'
        ),

        'combined_disk' => env(
            'ESIGN_COMBINED_DISK',
            'private'
        ),

        'directory' => trim(
            (string) env(
                'ESIGN_STORAGE_DIRECTORY',
                'private/esign'
            ),
            '/'
        ),

        'max_pdf_bytes' => 15728640,
    ],

    /*
    |--------------------------------------------------------------------------
    | BL combined package
    |--------------------------------------------------------------------------
    */

    'bl' => [
        'auto_start_lender' => (bool) env('ESIGN_BL_AUTO_START_LENDER', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    */

    'authorization' => [

    /*
    | Gate ability registered by ESignServiceProvider
    */

    'backend_ability' => env(
        'ESIGN_BACKEND_ABILITY',
        'manage-esign'
    ),

    /*
    | Backend roles allowed to manage borrower/lender eSign
    */

    'backend_roles' => array_values(
        array_filter(
            array_map(
                static fn (string $role): string =>
                    trim($role),
                explode(
                    ',',
                                       (string) env(
                        'ESIGN_BACKEND_ROLES',
                        'administrator,super_administrator,admin,backend-admin,loan-manager,credit,operation'
                    )
                )
            )
        )
    ),

    /*
    | Customer authorization mapping
    |
    | "auth_identifier" (Sanctum's getAuthIdentifier(), ie. users.id) is
    | NOT the same number as customer_lendings.customer_id — comparing
    | those two always fails for a real customer. The User model has its
    | own customer_id column (copied from the accepted lead) that
    | actually matches.
    */

    'customer_user_key' =>
        'customer_id',

    'customer_lending_key' =>
        'customer_id',
],

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    */

    'routes' => [

        'backend_prefix' =>
            'api/backend/esign',

        'customer_prefix' =>
            'api/customer/esign',

        'webhook_prefix' =>
            'api/esign',

        'backend_middleware' => [
            'api',
            'auth:sanctum',
            'throttle:esign-backend',
        ],

        'customer_middleware' => [
            'api',
            'auth:sanctum',
            'throttle:esign-customer',
        ],

        'webhook_middleware' => [
            'api',
            'throttle:esign-webhook',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue and polling
    |--------------------------------------------------------------------------
    */

    'queue' => env(
        'ESIGN_QUEUE',
        'default'
    ),

    'polling' => [

        'stale_after_minutes' => 5,

        'batch_size' => 100,
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit retention
    |--------------------------------------------------------------------------
    */

    'retention' => [

        'api_logs_days' => 90,

        'webhook_events_days' => 180,
    ],

    /*
    |--------------------------------------------------------------------------
    | Log redaction
    |--------------------------------------------------------------------------
    */

    'redact_keys' => [
        'authorization',
        'app_key',
        'secret_key',
        'token',
        'password',
        'otp',
        'signer',
        'url',
        'aadhaar',
        'pan',
    ],
];