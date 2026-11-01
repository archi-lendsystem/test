<?php

namespace Modules\BLSanctionCondition\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\BLSanctionCondition\Models\BLSanctionConditionMaster;

class BLSanctionConditionMasterSeeder extends Seeder
{
   
    public function run(): void
    {
        $conditions = [
            "Accepted sanction letter must be collected from borrower",
            "Loan agreement must be executed by borrower and co-borrower",
            "Demand promissory note must be signed and collected",
            "Letter of continuity must be signed and collected",
            "PDC / NACH / ECS mandate must be collected for repayment",
            "Borrower bank details must be verified for disbursement",
            "Processing fee and GST deduction must be accepted",
            "Legal verification must be clear with no adverse title remark",
            "Technical valuation must support approved LTV",
            "No negative bureau / dedupe / credit deviation should remain pending",
            "Guarantee agreement must be executed wherever applicable",
            "Security documents must be created in favour of lender",
        ];

        foreach ($conditions as $index => $title) {
            BLSanctionConditionMaster::updateOrCreate(
                ['condition_id' => $index + 1],
                [
                    'title'     => $title,
                    'is_active' => true,
                ]
            );
        }
    }
}
