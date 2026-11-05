<?php

namespace Modules\BLWorkflow\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class BLWorkflowTransitionSeeder extends Seeder
{
     public function run(): void
    {
        $transitions = [
            // LEAD_CREATED → LEAD_REVIEW (only after SUBMITTED)
            ['from' => 'LEAD_CREATED',      'to' => 'LEAD_REVIEW',       'allow_back' => false, 'required_status_code' => 'SUBMITTED'],

            // LEAD_REVIEW → CREDIT_PROCESSING (only after SUBMITTED — assignment unlocks here)
            ['from' => 'LEAD_REVIEW',       'to' => 'CREDIT_PROCESSING', 'allow_back' => false, 'required_status_code' => 'SUBMITTED'],
            ['from' => 'LEAD_REVIEW',       'to' => 'LEAD_CREATED',      'allow_back' => true,  'required_status_code' => null],

            // CREDIT_PROCESSING → LENDER_SELECTION
            ['from' => 'CREDIT_PROCESSING', 'to' => 'LENDER_SELECTION',  'allow_back' => false, 'required_status_code' => 'APPROVED'],
            ['from' => 'CREDIT_PROCESSING', 'to' => 'REJECTED',          'allow_back' => false, 'required_status_code' => 'REJECTED'],

            // LENDER_SELECTION → SANCTION
            ['from' => 'LENDER_SELECTION',  'to' => 'SANCTION',          'allow_back' => false, 'required_status_code' => 'SUBMITTED'],

            // SANCTION → INSURANCE_PRICING or REJECTED
            ['from' => 'SANCTION',          'to' => 'INSURANCE_PRICING', 'allow_back' => false, 'required_status_code' => 'SANCTIONED'],
            ['from' => 'SANCTION',          'to' => 'REJECTED',          'allow_back' => false, 'required_status_code' => 'REJECTED'],

            // INSURANCE_PRICING → DISBURSEMENT
            ['from' => 'INSURANCE_PRICING', 'to' => 'DISBURSEMENT',      'allow_back' => false, 'required_status_code' => 'COMPLETED'],

            // DISBURSEMENT → REPAYMENT
            ['from' => 'DISBURSEMENT',      'to' => 'REPAYMENT',         'allow_back' => false, 'required_status_code' => 'DISBURSED'],

            // REPAYMENT → CLOSED
            ['from' => 'REPAYMENT',         'to' => 'CLOSED',            'allow_back' => false, 'required_status_code' => 'CLOSED'],
        ];

        foreach ($transitions as $t) {
            $from = DB::table('b_l_workflow_stages')->where('stage_code', $t['from'])->first();
            $to   = DB::table('b_l_workflow_stages')->where('stage_code', $t['to'])->first();

            if (!$from || !$to) continue;

            DB::table('b_l_workflow_transitions')->updateOrInsert(
                [
                    'from_stage_id' => $from->id,
                    'to_stage_id'   => $to->id,
                ],
                [
                    'from_stage_id'        => $from->id,
                    'to_stage_id'          => $to->id,
                    'allow_back'           => $t['allow_back'],
                    'required_status_code' => $t['required_status_code'],
                    'updated_at'           => now(),
                    'created_at'           => DB::raw('COALESCE(created_at, NOW())'),
                ]
            );
        }
    }
}
