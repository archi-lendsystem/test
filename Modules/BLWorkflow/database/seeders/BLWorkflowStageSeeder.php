<?php

namespace Modules\BLWorkflow\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class BLWorkflowStageSeeder extends Seeder
{
     public function run(): void
    {
        $stages = [
            [
                'stage_code'         => 'LEAD_CREATED',
                'stage_name'         => 'Lead Created',
                'sequence_no'        => 1,
                'team_name'          => 'Sales',
                'is_reversible'      => true,
                'is_terminal'        => false,
                'allow_reassignment' => false,
                'allow_edit'         => true,
            ],
            [
                'stage_code'         => 'LEAD_REVIEW',
                'stage_name'         => 'Lead Review',
                'sequence_no'        => 2,
                'team_name'          => 'Sales',
                'is_reversible'      => true,
                'is_terminal'        => false,
                'allow_reassignment' => false, // assign block until SUBMITTED
                'allow_edit'         => true,
            ],
            [
                'stage_code'         => 'CREDIT_PROCESSING',
                'stage_name'         => 'Credit Processing',
                'sequence_no'        => 3,
                'team_name'          => 'Credit',
                'is_reversible'      => true,
                'is_terminal'        => false,
                'allow_reassignment' => true,
                'allow_edit'         => true,
            ],
            [
                'stage_code'         => 'LENDER_SELECTION',
                'stage_name'         => 'Lender Selection',
                'sequence_no'        => 4,
                'team_name'          => 'Credit',
                'is_reversible'      => false,
                'is_terminal'        => false,
                'allow_reassignment' => true,
                'allow_edit'         => true,
            ],
            [
                'stage_code'         => 'SANCTION',
                'stage_name'         => 'Sanction',
                'sequence_no'        => 5,
                'team_name'          => 'Credit',
                'is_reversible'      => false,
                'is_terminal'        => false,
                'allow_reassignment' => false,
                'allow_edit'         => false,
            ],
            [
                'stage_code'         => 'INSURANCE_PRICING',
                'stage_name'         => 'Insurance & Pricing',
                'sequence_no'        => 6,
                'team_name'          => 'Operations',
                'is_reversible'      => false,
                'is_terminal'        => false,
                'allow_reassignment' => false,
                'allow_edit'         => false,
            ],
            [
                'stage_code'         => 'DISBURSEMENT',
                'stage_name'         => 'Disbursement',
                'sequence_no'        => 7,
                'team_name'          => 'Operations',
                'is_reversible'      => false,
                'is_terminal'        => false,
                'allow_reassignment' => false,
                'allow_edit'         => false,
            ],
            [
                'stage_code'         => 'REPAYMENT',
                'stage_name'         => 'Repayment',
                'sequence_no'        => 8,
                'team_name'          => 'Operations',
                'is_reversible'      => false,
                'is_terminal'        => false,
                'allow_reassignment' => false,
                'allow_edit'         => false,
            ],
            [
                'stage_code'         => 'CLOSED',
                'stage_name'         => 'Closed',
                'sequence_no'        => 9,
                'team_name'          => 'System',
                'is_reversible'      => false,
                'is_terminal'        => true,
                'allow_reassignment' => false,
                'allow_edit'         => false,
            ],
            [
                'stage_code'         => 'REJECTED',
                'stage_name'         => 'Rejected',
                'sequence_no'        => 10,
                'team_name'          => 'System',
                'is_reversible'      => false,
                'is_terminal'        => true,
                'allow_reassignment' => false,
                'allow_edit'         => false,
            ],
        ];

        foreach ($stages as $stage) {
            DB::table('b_l_workflow_stages')->updateOrInsert(
                ['stage_code' => $stage['stage_code']],
                array_merge($stage, [
                    'updated_at' => now(),
                    'created_at' => DB::raw('COALESCE(created_at, NOW())'),
                ])
            );
        }
    }
}
