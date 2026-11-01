<?php

/*
 * All existing and new leads use the current product journey.
 *
 * Register shared steps once in "steps".
 * Define each product's ordered journey in "products".
 */
$start = ['lead-details', 'applicant', 'income'];

$finish = ['reference', 'banking', 'documents', 'submit'];

return [
    'steps' => [
        'lead-details' => [
            'label' => 'Creation',
            'requires_lead' => false,
            'submission_requirement' => 'none',
        ],

        'applicant' => [
            'label' => 'Applicant',
            'requires_lead' => true,
            'submission_requirement' => 'hard',

            'completion' => [
                'label' => 'Applicant Details',
                'message' => 'Applicant Details form is incomplete.',
                'required_action' => 'Please complete Applicant Details first.',
                'stage_names' => [
                    'Applicant Details',
                ],
                'action_types' => [
                    'APPLICANT_DETAILS_COMPLETED',
                ],
            ],
        ],

        'income' => [
            'label' => 'Income',
            'requires_lead' => true,
            'submission_requirement' => 'soft',

            'completion' => [
                'label' => 'Income Details',
                'message' => 'Income Details form is incomplete or not saved.',
                'required_action' => 'Please complete the Income Details form.',
                'stage_names' => [
                    'Income Details',
                ],
                'action_types' => [
                    'INCOME_DETAILS_COMPLETED',
                ],
            ],
        ],

        'reference' => [
            'label' => 'Reference',
            'requires_lead' => true,
            'submission_requirement' => 'soft',

            'completion' => [
                'label' => 'Reference Details',
                'message' => 'Reference Details form is incomplete or not saved.',
                'required_action' => 'Please complete the Reference Details form.',
                'stage_names' => [
                    'Reference Details',
                ],
                'action_types' => [
                    'REFERENCE_DETAILS_COMPLETED',
                ],
            ],
        ],

        'banking' => [
            'label' => 'Banking',
            'requires_lead' => true,
            'submission_requirement' => 'none',
        ],

        'property' => [
            'label' => 'Property',
            'requires_lead' => true,
            'submission_requirement' => 'none',
        ],

        'seller' => [
            'label' => 'Seller',
            'requires_lead' => true,
            'submission_requirement' => 'none',
        ],

        'documents' => [
            'label' => 'Documents',
            'requires_lead' => true,
            'submission_requirement' => 'soft',

            'completion' => [
                'label' => 'Document Uploads',
                'message' => 'Required documents are not uploaded or incomplete.',
                'required_action' => 'Please upload and complete all required documents.',
                'stage_names' => [
                    'Document Uploads',
                    'Document Collection',
                ],
                'action_types' => [
                    'DOCUMENT_UPLOADS_COMPLETED',
                ],
            ],
        ],

        'submit' => [
            'label' => 'Submit',
            'requires_lead' => true,
            'submission_requirement' => 'none',
        ],
    ],

    'products' => [
        'BL' => [
            ...$start,
            ...$finish,
        ],

        'PL' => [
            ...$start,
            ...$finish,
        ],

        'LAP' => [
            ...$start,
            'property',
            ...$finish,
        ],

        'HL' => [
            ...$start,
            'seller',
            'property',
            ...$finish,
        ],
    ],
];