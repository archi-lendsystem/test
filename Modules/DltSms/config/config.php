<?php

// return [

//     /*
//     |--------------------------------------------------------------------------
//     | Module Name
//     |--------------------------------------------------------------------------
//     */
//     'name' => 'DltSms',

//     /*
//     |--------------------------------------------------------------------------
//     | Default SMS Provider
//     |--------------------------------------------------------------------------
//     |
//     | Future mein provider change/add hone par application code ko
//     | unnecessarily modify nahi karna padega.
//     |
//     */
//     'default_provider' => env(
//         'DLT_SMS_PROVIDER',
//         'mishtel'
//     ),

//     /*
//     |--------------------------------------------------------------------------
//     | Mishtel Configuration
//     |--------------------------------------------------------------------------
//     */
//     'mishtel' => [

//         /*
//         |--------------------------------------------------------------------------
//         | API Configuration
//         |--------------------------------------------------------------------------
//         */
//         'base_url' => env(
//             'MISHTEL_BASE_URL'
//         ),

//         /*
//          * Supported authentication modes:
//          *
//          * api_key
//          * credentials
//          */
//         'auth_mode' => env(
//             'MISHTEL_AUTH_MODE',
//             'credentials'
//         ),

//         /*
//         |--------------------------------------------------------------------------
//         | Authentication
//         |--------------------------------------------------------------------------
//         */
//         'api_key' => env(
//             'MISHTEL_API_KEY'
//         ),

//         'username' => env(
//             'MISHTEL_USERNAME'
//         ),

//         'password' => env(
//             'MISHTEL_PASSWORD'
//         ),

//         /*
//         |--------------------------------------------------------------------------
//         | SMS Configuration
//         |--------------------------------------------------------------------------
//         */
//         'sender_id' => env(
//             'MISHTEL_SENDER_ID'
//         ),

//         'channel' => env(
//             'MISHTEL_CHANNEL',
//             'Trans'
//         ),

//         'route_id' => env(
//             'MISHTEL_ROUTE_ID'
//         ),

//         /*
//          * DCS:
//          *
//          * 0 = Normal SMS
//          * 8 = Unicode SMS
//          */
//         'dcs' => (int) env(
//             'MISHTEL_DCS',
//             0
//         ),

//         /*
//          * Flash SMS:
//          *
//          * 0 = Normal SMS
//          * 1 = Flash SMS
//          */
//         'flash_sms' => (int) env(
//             'MISHTEL_FLASH_SMS',
//             0
//         ),

//         /*
//         |--------------------------------------------------------------------------
//         | DLT Configuration
//         |--------------------------------------------------------------------------
//         */
//         'pe_id' => env(
//             'MISHTEL_PE_ID'
//         ),

//         'dlt_template_id' => env(
//             'MISHTEL_DLT_TEMPLATE_ID'
//         ),

//         'telemarketer_id' => env(
//             'MISHTEL_TELEMARKETER_ID'
//         ),

//         /*
//         |--------------------------------------------------------------------------
//         | HTTP Client Configuration
//         |--------------------------------------------------------------------------
//         */
//         'timeout' => (int) env(
//             'MISHTEL_TIMEOUT',
//             15
//         ),

//         'connect_timeout' => (int) env(
//             'MISHTEL_CONNECT_TIMEOUT',
//             5
//         ),

//     ],

//     /*
//     |--------------------------------------------------------------------------
//     | DLT Approved Templates
//     |--------------------------------------------------------------------------
//     |
//     | IMPORTANT:
//     |
//     | Approved DLT message wording should not be changed casually.
//     | DLT providers may reject messages when the submitted message does
//     | not match the approved template.
//     |
//     */
//     'templates' => [

//         /*
//         |--------------------------------------------------------------------------
//         | Customer Lending OTP
//         |--------------------------------------------------------------------------
//         */
//         'customer_lending_otp' => [

//             /*
//              * DLT variable placeholder exactly as approved.
//              */
//             'placeholder' => '{#var#}',

//             /*
//              * Approved DLT template.
//              *
//              * Do not change punctuation, wording or spacing without
//              * updating/re-approving the corresponding DLT template.
//              */
//             'message' => 'Thank you for your enquiry with Friendlysalary.com Your OTP for enquiry verification code is {#var#}. Valid for 5 minutes. Never share your OTP to anyone.',

//             /*
//              * OTP validity advertised in the approved SMS template.
//              *
//              * CustomerLending backend expiry must match this value.
//              */
//             'otp_expiry_minutes' => 5,

//         ],

//         /*
//         |--------------------------------------------------------------------------
//         | Staff Login OTP
//         |--------------------------------------------------------------------------
//         */
//         'staff_login_otp' => [

//             /*
//              * DLT variable placeholder exactly as approved.
//              */
//             'placeholder' => '{#var#}',

//             /*
//              * Approved DLT template.
//              *
//              * Do not change punctuation, wording or spacing without
//              * updating/re-approving the corresponding DLT template.
//              */
//             'message' => 'Your OTP for FriendlySalary staff login is {#var#}. Valid for 5 minutes. Never share your OTP.',

//             /*
//              * OTP validity advertised in the approved SMS template.
//              *
//              * Auth module backend expiry must match this value.
//              */
//             'otp_expiry_minutes' => 5,

//         ],

//     ],

// ];

return [

    /*
    |--------------------------------------------------------------------------
    | Module Name
    |--------------------------------------------------------------------------
    */
    'name' => 'DltSms',


    /*
    |--------------------------------------------------------------------------
    | Default SMS Provider
    |--------------------------------------------------------------------------
    */
    'default_provider' => env(
        'DLT_SMS_PROVIDER',
        'mishtel'
    ),


    /*
    |--------------------------------------------------------------------------
    | Mishtel Configuration
    |--------------------------------------------------------------------------
    */
    'mishtel' => [

        /*
        |--------------------------------------------------------------------------
        | API Configuration
        |--------------------------------------------------------------------------
        */
        'base_url' => env(
            'MISHTEL_BASE_URL'
        ),


        /*
         * Supported authentication modes:
         *
         * api_key
         * credentials
         */
        'auth_mode' => env(
            'MISHTEL_AUTH_MODE',
            'credentials'
        ),


        /*
        |--------------------------------------------------------------------------
        | Authentication
        |--------------------------------------------------------------------------
        */
        'api_key' => env(
            'MISHTEL_API_KEY'
        ),

        'username' => env(
            'MISHTEL_USERNAME'
        ),

        'password' => env(
            'MISHTEL_PASSWORD'
        ),


        /*
        |--------------------------------------------------------------------------
        | SMS Configuration
        |--------------------------------------------------------------------------
        */
        'sender_id' => env(
            'MISHTEL_SENDER_ID'
        ),


        'channel' => env(
            'MISHTEL_CHANNEL',
            'Trans'
        ),


        'route_id' => env(
            'MISHTEL_ROUTE_ID'
        ),


        /*
         * DCS:
         *
         * 0 = Normal SMS
         * 8 = Unicode SMS
         */
        'dcs' => (int) env(
            'MISHTEL_DCS',
            0
        ),


        /*
         * Flash SMS:
         *
         * 0 = Normal SMS
         * 1 = Flash SMS
         */
        'flash_sms' => (int) env(
            'MISHTEL_FLASH_SMS',
            0
        ),


        /*
        |--------------------------------------------------------------------------
        | DLT Configuration
        |--------------------------------------------------------------------------
        */
        'pe_id' => env(
            'MISHTEL_PE_ID'
        ),


        /*
         * Default fallback DLT template.
         *
         * Individual module templates use their own IDs below.
         */
        'dlt_template_id' => env(
            'MISHTEL_DLT_TEMPLATE_ID'
        ),


        'telemarketer_id' => env(
            'MISHTEL_TELEMARKETER_ID'
        ),


        /*
        |--------------------------------------------------------------------------
        | HTTP Client Configuration
        |--------------------------------------------------------------------------
        */
        'timeout' => (int) env(
            'MISHTEL_TIMEOUT',
            15
        ),


        'connect_timeout' => (int) env(
            'MISHTEL_CONNECT_TIMEOUT',
            5
        ),


    ],


    /*
    |--------------------------------------------------------------------------
    | DLT Approved Templates
    |--------------------------------------------------------------------------
    |
    | IMPORTANT:
    |
    | Approved DLT message wording should not be changed casually.
    |
    |--------------------------------------------------------------------------
    */
    'templates' => [


        /*
        |--------------------------------------------------------------------------
        | Customer Lending OTP
        |--------------------------------------------------------------------------
        |
        | Customer Portal OTP Template
        |
        | DLT Template ID:
        | 1777178816505222688
        |
        |--------------------------------------------------------------------------
        */
        'customer_lending_otp' => [


            /*
             * DLT Template ID
             */
            'dlt_template_id' => env(
                'MISHTEL_CUSTOMER_LENDING_DLT_TEMPLATE_ID',
                '1777178816505222688'
            ),


            /*
             * DLT variable placeholder exactly as approved.
             */
            'placeholder' => '{#var#}',


            /*
             * Approved DLT template.
             */
            'message' =>
                'Your OTP for FriendlySalary Customer Portal login verification is {#var#}. Valid for 5 minutes. Never share your OTP with anyone.',


            /*
             * OTP validity
             */
            'otp_expiry_minutes' => 5,


        ],



        /*
        |--------------------------------------------------------------------------
        | Staff Login OTP
        |--------------------------------------------------------------------------
        |
        | Staff Portal OTP Template
        |
        | DLT Template ID:
        | 1777178816496374488
        |
        |--------------------------------------------------------------------------
        */
        'staff_login_otp' => [


            /*
             * DLT Template ID
             */
            'dlt_template_id' => env(
                'MISHTEL_STAFF_LOGIN_DLT_TEMPLATE_ID',
                '1777178816496374488'
            ),


            /*
             * DLT variable placeholder exactly as approved.
             */
            'placeholder' => '{#var#}',


            /*
             * Approved DLT template.
             */
            'message' =>
                'Your OTP for FriendlySalary Staff Portal login verification is {#var#}. Valid for 5 minutes. Never share your OTP with anyone.',


            /*
             * OTP validity
             */
            'otp_expiry_minutes' => 5,


        ],


    ],


];