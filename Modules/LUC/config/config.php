<?php

return [
    'name' => 'LUC',


    'due_after_days' => 15,


    'disk' => 'private',


    'photo_directory' => 'luc-reports',


    'audit_channel' => 'lms_audit',

   
    'utilization_statuses' => [
        'fully_utilized',
        'partly_utilized',
        'mis_utilized',
    ],

   
    'note_options' => [
        'fully_utilized' => [
            'as_per_purpose' => 'Utilized as per stated purpose',
            'business_expansion' => 'Used for business expansion',
            'other' => 'Other',
        ],
        'partly_utilized' => [
            'partial_business_use' => 'Partially used for business purpose',
            'balance_in_account' => 'Balance amount still in account',
            'other' => 'Other',
        ],
        'mis_utilized' => [
            'personal_use' => 'Used for personal purpose',
            'diverted_to_other_account' => 'Diverted to another account',
            'unable_to_verify' => 'Unable to verify utilization',
            'other' => 'Other',
        ],
    ],
];