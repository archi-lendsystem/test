<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Product Scheme Configuration Options
    |--------------------------------------------------------------------------
    |
    | These values are used by Product Scheme checklist UI.
    | Dropdown values are not stored as master rows in product_schemes table.
    |
    */

    'sub_loan_types' => [
        'weekly'    => 'Weekly Loan',
        'bi_weekly' => 'By-Weekly Loan',
        'monthly'   => 'Monthly Loan',
        'daily'     => 'Daily Loan',
        'bullet'    => 'Bullet Loan',
        'payday'    => 'Payday Loan',
    ],

      'product_sub_loan_types' => [
        'LAP' => ['monthly'],
        'HL'  => ['monthly'],
        'BL'  => ['weekly', 'bi_weekly', 'monthly', 'daily', 'bullet', 'payday'],
        'PL'  => ['weekly', 'bi_weekly', 'monthly', 'daily', 'bullet', 'payday'],
    ],

    'repayment_frequencies' => [
        'daily'       => 'Daily',
        'weekly'      => 'Weekly',
        'bi_weekly'   => 'Bi-Weekly',
        'monthly'     => 'Monthly',
        'quarterly'   => 'Quarterly',
        'half_yearly' => 'Half Yearly',
        'yearly'      => 'Yearly',
        'bullet'      => 'Bullet',
        'one_time'  => 'One Time',
    ],

    'calculation_methods' => [
        'reducing_balance' => 'Reducing Balance',
        'flat_rate'        => 'Flat Rate',
        'daily_reducing'   => 'Daily Reducing',
    ],

    'overdue_interest_on' => [
        'principal_outstanding' => 'Principal Outstanding',
        'emi_amount'            => 'EMI Amount',
        'total_outstanding'     => 'Total Outstanding',
    ],

    'emi_round_off_types' => [
        'nearest' => 'Nearest',
        'floor'   => 'Floor',
        'ceil'    => 'Ceil',
        'none'    => 'None',
    ],

    'tenure_types' => [
    'fixed' => 'Fixed Tenure',
    'range' => 'Tenure Range',
],

    'tenure_units' => [
        'days'   => 'Days',
        'weeks'  => 'Weeks',
        'months' => 'Months',
        'years'  => 'Years',
        'one_time'  => 'One Time',
    ],

    'status_options' => [
        1 => 'Active',
        0 => 'Inactive',
    ],

    /*
    |--------------------------------------------------------------------------
    | Product Scheme Form Fields
    |--------------------------------------------------------------------------
    |
    | These fields help frontend build the Product Scheme checklist form.
    |
    */

    'form_fields' => [

        'product_id' => [
            'key'      => 'product_id',
            'label'    => 'Product',
            'type'     => 'select',
            'required' => true,
        ],

        'scheme_name' => [
            'key'         => 'scheme_name',
            'label'       => 'Scheme Name',
            'type'        => 'text',
            'required'    => false,
            'placeholder' => 'Enter scheme name',
        ],

        'sub_loan_type' => [
            'key'      => 'sub_loan_type',
            'label'    => 'Sub Loan Type',
            'type'     => 'select',
            'required' => true,
            'options'  => 'sub_loan_types',
        ],

        'interest_rate' => [
            'key'         => 'interest_rate',
            'label'       => 'Interest Rate (%)',
            'type'        => 'number',
            'required'    => true,
            'min'         => 0,
            'max'         => 100,
            'step'        => 0.01,
            'placeholder' => 'Enter interest rate',
        ],

        'repayment_frequency' => [
            'key'      => 'repayment_frequency',
            'label'    => 'Repayment Frequency',
            'type'     => 'select',
            'required' => true,
            'options'  => 'repayment_frequencies',
        ],

        'lpf_rate' => [
            'key'         => 'lpf_rate',
            'label'       => 'LPF Rate (%)',
            'type'        => 'number',
            'required'    => false,
            'min'         => 0,
            'max'         => 100,
            'step'        => 0.01,
            'placeholder' => 'Enter LPF rate',
        ],

        'moratorium_days' => [
            'key'         => 'moratorium_days',
            'label'       => 'Moratorium In Days',
            'type'        => 'number',
            'required'    => false,
            'min'         => 0,
            'max'         => 365,
            'step'        => 1,
            'placeholder' => 'Enter moratorium days',
        ],

        'calculation_method' => [
            'key'      => 'calculation_method',
            'label'    => 'Calculation Method',
            'type'     => 'select',
            'required' => true,
            'options'  => 'calculation_methods',
        ],

        'overdue_interest_on' => [
            'key'      => 'overdue_interest_on',
            'label'    => 'Overdue Interest On',
            'type'     => 'select',
            'required' => true,
            'options'  => 'overdue_interest_on',
        ],

        'emi_round_off_type' => [
            'key'      => 'emi_round_off_type',
            'label'    => 'EMI Round Off Type',
            'type'     => 'select',
            'required' => true,
            'options'  => 'emi_round_off_types',
        ],

        'emi_round_up_value' => [
            'key'         => 'emi_round_up_value',
            'label'       => 'EMI Round Up',
            'type'        => 'number',
            'required'    => false,
            'min'         => 0,
            'step'        => 1,
            'placeholder' => 'Enter EMI round up value',
        ],

        // 'tenure_value' => [
        //     'key'         => 'tenure_value',
        //     'label'       => 'Tenure',
        //     'type'        => 'number',
        //     'required'    => true,
        //     'min'         => 1,
        //     'max'         => 600,
        //     'step'        => 1,
        //     'placeholder' => 'Enter tenure',
        // ],

        'tenure_type' => [
    'key'      => 'tenure_type',
    'label'    => 'Tenure Type',
    'type'     => 'select',
    'required' => true,
    'options'  => 'tenure_types',
],

'tenure_value' => [
    'key'           => 'tenure_value',
    'label'         => 'Tenure',
    'type'          => 'number',
    'required'      => false,
    'required_when' => [
        'tenure_type' => 'fixed',
    ],
    'min'           => 1,
    'max'           => 600,
    'step'          => 1,
    'placeholder'   => 'Enter fixed tenure',
],

'minimum_tenure' => [
    'key'           => 'minimum_tenure',
    'label'         => 'Minimum Tenure',
    'type'          => 'number',
    'required'      => false,
    'required_when' => [
        'tenure_type' => 'range',
    ],
    'min'           => 1,
    'max'           => 600,
    'step'          => 1,
    'placeholder'   => 'Enter minimum tenure',
],

'maximum_tenure' => [
    'key'           => 'maximum_tenure',
    'label'         => 'Maximum Tenure',
    'type'          => 'number',
    'required'      => false,
    'required_when' => [
        'tenure_type' => 'range',
    ],
    'min'           => 1,
    'max'           => 600,
    'step'          => 1,
    'placeholder'   => 'Enter maximum tenure',
],

        'tenure_unit' => [
            'key'      => 'tenure_unit',
            'label'    => 'Tenure Unit',
            'type'     => 'select',
            'required' => true,
            'options'  => 'tenure_units',
        ],

        'valid_from' => [
            'key'      => 'valid_from',
            'label'    => 'Start Date',
            'type'     => 'date',
            'required' => false,
        ],

        'valid_till' => [
            'key'      => 'valid_till',
            'label'    => 'End Date',
            'type'     => 'date',
            'required' => false,
        ],

        'remarks' => [
            'key'         => 'remarks',
            'label'       => 'Remarks',
            'type'        => 'textarea',
            'required'    => false,
            'placeholder' => 'Enter remarks',
        ],

        'is_active' => [
            'key'      => 'is_active',
            'label'    => 'Status',
            'type'     => 'select',
            'required' => true,
            'options'  => 'status_options',
        ],


    ],
];