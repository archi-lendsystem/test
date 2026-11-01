<?php

return [
    'name' => 'Repayment',

    'charges' => [
        'gst_percentage' => env('REPAYMENT_CHARGE_GST_PERCENTAGE', 18),

        // Flat bounce/mandate-return fee, before GST.
        'bounce_amount' => env('REPAYMENT_BOUNCE_CHARGE_AMOUNT', 500),

        // Penal = this percentage of the overdue amount, per full
        // penal_period_days overdue (min. one period).
        'penal_percentage_per_period' => env('REPAYMENT_PENAL_PERCENTAGE', 3),
        'penal_period_days' => env('REPAYMENT_PENAL_PERIOD_DAYS', 30),
    ],
];
