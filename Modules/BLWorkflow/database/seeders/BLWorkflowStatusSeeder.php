<?php

namespace Modules\BLWorkflow\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class BLWorkflowStatusSeeder extends Seeder
{
     public function run(): void
    {
        $statuses = [

            'LEAD_CREATED' => [
                ['status_code' => 'DRAFT',                    'status_name' => 'Draft',                      'is_final' => false],
                ['status_code' => 'EMAIL_VERIFIED',           'status_name' => 'Email Verified',             'is_final' => false],
                ['status_code' => 'APPLICANT_DETAILS_DONE',   'status_name' => 'Applicant Details Submitted','is_final' => false],
                ['status_code' => 'CO_APPLICANT_ADDED',       'status_name' => 'Co-Applicant Added',         'is_final' => false],
                ['status_code' => 'BUREAU_GENERATED',         'status_name' => 'Bureau Generated',           'is_final' => false],
                ['status_code' => 'INCOME_SUBMITTED',         'status_name' => 'Income Submitted',           'is_final' => false],
                ['status_code' => 'PROPERTY_SUBMITTED',       'status_name' => 'Property Submitted',         'is_final' => false],
                ['status_code' => 'REFERENCE_SUBMITTED',      'status_name' => 'Reference Submitted',        'is_final' => false],
                ['status_code' => 'BANKING_SUBMITTED',        'status_name' => 'Banking Submitted',          'is_final' => false],
                ['status_code' => 'DOCUMENTS_SUBMITTED',      'status_name' => 'Documents Submitted',        'is_final' => false],
                ['status_code' => 'SUBMITTED',                'status_name' => 'Submitted',                  'is_final' => true],
            ],

            'LEAD_REVIEW' => [
                ['status_code' => 'PENDING_DEDUPE',   'status_name' => 'Pending Dedupe',    'is_final' => false],
                ['status_code' => 'DEDUPE_DONE',      'status_name' => 'Dedupe Done',       'is_final' => false],
                ['status_code' => 'IMD_PENDING',      'status_name' => 'IMD Pending',       'is_final' => false],
                ['status_code' => 'IMD_DONE',         'status_name' => 'IMD Done',          'is_final' => false],
                ['status_code' => 'SUBMITTED',        'status_name' => 'Submitted',         'is_final' => true],
            ],

            'CREDIT_PROCESSING' => [
                ['status_code' => 'BACK_ANALYSIS_PENDING',      'status_name' => 'Back Analysis Pending',      'is_final' => false],
                ['status_code' => 'BACK_ANALYSIS_DONE',         'status_name' => 'Back Analysis Done',         'is_final' => false],
                ['status_code' => 'INCOME_ANALYSIS_PENDING',    'status_name' => 'Income Analysis Pending',    'is_final' => false],
                ['status_code' => 'INCOME_ANALYSIS_DONE',       'status_name' => 'Income Analysis Done',       'is_final' => false],
                ['status_code' => 'TELE_VERIFICATION_PENDING',  'status_name' => 'Tele Verification Pending',  'is_final' => false],
                ['status_code' => 'TELE_VERIFICATION_DONE',     'status_name' => 'Tele Verification Done',     'is_final' => false],
                ['status_code' => 'REFERENCE_CHECK_PENDING',    'status_name' => 'Reference Check Pending',    'is_final' => false],
                ['status_code' => 'REFERENCE_CHECK_DONE',       'status_name' => 'Reference Check Done',       'is_final' => false],
                ['status_code' => 'CREDIT_VISIT_PENDING',       'status_name' => 'Credit Visit Pending',       'is_final' => false],
                ['status_code' => 'CREDIT_VISIT_DONE',          'status_name' => 'Credit Visit Done',          'is_final' => false],
                ['status_code' => 'PROPERTY_VISIT_PENDING',     'status_name' => 'Property Visit Pending',     'is_final' => false],
                ['status_code' => 'PROPERTY_VISIT_DONE',        'status_name' => 'Property Visit Done',        'is_final' => false],
                ['status_code' => 'APPROVED',                   'status_name' => 'Approved',                   'is_final' => true],
                ['status_code' => 'REJECTED',                   'status_name' => 'Rejected',                   'is_final' => true],
            ],

            'LENDER_SELECTION' => [
                ['status_code' => 'PENDING',          'status_name' => 'Pending',           'is_final' => false],
                ['status_code' => 'LENDER_SELECTED',  'status_name' => 'Lender Selected',   'is_final' => false],
                ['status_code' => 'SUBMITTED',        'status_name' => 'Submitted',         'is_final' => true],
            ],

            'SANCTION' => [
                ['status_code' => 'PENDING',    'status_name' => 'Pending',    'is_final' => false],
                ['status_code' => 'IN_REVIEW',  'status_name' => 'In Review',  'is_final' => false],
                ['status_code' => 'SANCTIONED', 'status_name' => 'Sanctioned', 'is_final' => true],
                ['status_code' => 'REJECTED',   'status_name' => 'Rejected',   'is_final' => true],
            ],

            'INSURANCE_PRICING' => [
                ['status_code' => 'PENDING',     'status_name' => 'Pending',     'is_final' => false],
                ['status_code' => 'IN_PROGRESS', 'status_name' => 'In Progress', 'is_final' => false],
                ['status_code' => 'COMPLETED',   'status_name' => 'Completed',   'is_final' => true],
            ],

            'DISBURSEMENT' => [
                ['status_code' => 'PENDING',   'status_name' => 'Pending',   'is_final' => false],
                ['status_code' => 'INITIATED', 'status_name' => 'Initiated', 'is_final' => false],
                ['status_code' => 'DISBURSED', 'status_name' => 'Disbursed', 'is_final' => true],
            ],

            'REPAYMENT' => [
                ['status_code' => 'ACTIVE', 'status_name' => 'Active', 'is_final' => false],
                ['status_code' => 'CLOSED', 'status_name' => 'Closed', 'is_final' => true],
            ],

            'CLOSED' => [
                ['status_code' => 'CLOSED', 'status_name' => 'Closed', 'is_final' => true],
            ],

            'REJECTED' => [
                ['status_code' => 'REJECTED', 'status_name' => 'Rejected', 'is_final' => true],
            ],
        ];

        foreach ($statuses as $stageCode => $stageStatuses) {
            $stage = DB::table('b_l_workflow_stages')
                ->where('stage_code', $stageCode)
                ->first();

            if (!$stage) continue;

            foreach ($stageStatuses as $status) {
                DB::table('b_l_workflow_statuses')->updateOrInsert(
                    [
                        'stage_id'    => $stage->id,
                        'status_code' => $status['status_code'],
                    ],
                    [
                        'stage_id'    => $stage->id,
                        'status_code' => $status['status_code'],
                        'status_name' => $status['status_name'],
                        'is_final'    => $status['is_final'],
                        'updated_at'  => now(),
                        'created_at'  => DB::raw('COALESCE(created_at, NOW())'),
                    ]
                );
            }
        }
    }
}
