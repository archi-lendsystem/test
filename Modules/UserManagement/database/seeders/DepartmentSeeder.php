<?php

namespace Modules\UserManagement\Database\Seeders;

use Modules\UserManagement\Models\Department;

use Illuminate\Database\Seeder;

class DepartmentSeeder extends Seeder
{
    
   public function run(): void
    {
        $departments = [
            [
                'name' => 'Sales',
                'code' => 'SALES',
                'description' => 'Handles lead generation and loan sales',
            ],
            [
                'name' => 'Credit',
                'code' => 'CREDIT',
                'description' => 'Handles credit evaluation and underwriting',
            ],
            [
                'name' => 'Risk Management',
                'code' => 'RISK',
                'description' => 'Monitors portfolio and lending risks',
            ],
            [
                'name' => 'Collections',
                'code' => 'COLLECTION',
                'description' => 'Handles EMI follow-ups and recovery',
            ],
            [
                'name' => 'Operations',
                'code' => 'OPS',
                'description' => 'Manages day-to-day NBFC operations',
            ],
            [
                'name' => 'Customer Support',
                'code' => 'SUPPORT',
                'description' => 'Handles customer queries and complaints',
            ],
            [
                'name' => 'Compliance',
                'code' => 'COMPLIANCE',
                'description' => 'Ensures RBI and regulatory compliance',
            ],
            [
                'name' => 'Legal',
                'code' => 'LEGAL',
                'description' => 'Handles legal documentation and cases',
            ],
            [
                'name' => 'Finance & Accounts',
                'code' => 'FINANCE',
                'description' => 'Handles accounting and financial reporting',
            ],
            [
                'name' => 'Human Resource',
                'code' => 'HR',
                'description' => 'Handles employee management and recruitment',
            ],
            [
                'name' => 'Information Technology',
                'code' => 'IT',
                'description' => 'Handles software, servers, and infrastructure',
            ],
            [
                'name' => 'Audit',
                'code' => 'AUDIT',
                'description' => 'Performs internal and external audits',
            ],
            [
                'name' => 'Loan Processing',
                'code' => 'LOAN_PROCESS',
                'description' => 'Handles application verification and processing',
            ],
            [
                'name' => 'Disbursement',
                'code' => 'DISBURSE',
                'description' => 'Handles loan disbursal process',
            ],
            [
                'name' => 'Recovery',
                'code' => 'RECOVERY',
                'description' => 'Handles overdue and NPA recovery cases',
            ],
            [
                'name' => 'Business Development',
                'code' => 'BD',
                'description' => 'Handles partnerships and business expansion',
            ],
            [
                'name' => 'Marketing',
                'code' => 'MARKETING',
                'description' => 'Handles branding and marketing campaigns',
            ],
            [
                'name' => 'Vendor Management',
                'code' => 'VENDOR',
                'description' => 'Handles third-party vendors and agencies',
            ],
            [
                'name' => 'Training & Development',
                'code' => 'TRAINING',
                'description' => 'Handles employee training programs',
            ],
            [
                'name' => 'Admin',
                'code' => 'ADMIN',
                'description' => 'Handles office administration and facilities',
            ],
        ];

        foreach ($departments as $department) {
            Department::updateOrCreate(
                ['code' => $department['code']],
                $department
            );
        }
    }
}
