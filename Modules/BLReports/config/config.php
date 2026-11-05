<?php

return [
    'name' => 'BLReports',

    'company' => [
        'name' => env(
            'BL_REPORT_COMPANY_NAME',
            config('app.name')
        ),

        'cin' => env('BL_REPORT_COMPANY_CIN'),

        'gst_number' => env(
            'BL_REPORT_COMPANY_GST_NUMBER'
        ),

        'registered_office' => env(
            'BL_REPORT_REGISTERED_OFFICE'
        ),

        'corporate_office' => env(
            'BL_REPORT_CORPORATE_OFFICE'
        ),

        'phone' => env('BL_REPORT_SUPPORT_PHONE'),

        'email' => env('BL_REPORT_SUPPORT_EMAIL'),

        'grievance_officer' => env(
            'BL_REPORT_GRIEVANCE_OFFICER'
        ),

        'grievance_email' => env(
            'BL_REPORT_GRIEVANCE_EMAIL'
        ),
    ],
];