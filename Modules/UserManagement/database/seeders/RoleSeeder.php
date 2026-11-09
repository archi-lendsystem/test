<?php

namespace Modules\UserManagement\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\UserManagement\Models\Role;

class RoleSeeder extends Seeder
{
   
    public function run(): void
    {
        $roles = [

            [
                'name'         => 'super_administrative',
                'display_name' => 'Super Administrative',
                'code'         => 'SUPER_ADMINISTRATIVE',
                'guard_name'   => 'web',
                'status'       => true,
            ],

            [
                'name'         => 'administrative',
                'display_name' => 'Administrative',
                'code'         => 'ADMINISTRATIVE',
                'guard_name'   => 'web',
                'status'       => true,
            ],

           
            [
                'name'         => 'sales_person',
                'display_name' => 'Sales Person',
                'code'         => 'SALES_PERSON',
                'guard_name'   => 'web',
                'status'       => true,
            ],

            [
                'name'         => 'sales_manager',
                'display_name' => 'Sales Manager',
                'code'         => 'SALES_MANAGER',
                'guard_name'   => 'web',
                'status'       => true,
            ],

           
            // [
            //     'name'         => 'ops_maker',
            //     'display_name' => 'Ops Maker',
            //     'code'         => 'OPS_MAKER',
            //     'guard_name'   => 'web',
            //     'status'       => true,
            // ],

            // [
            //     'name'         => 'ops_checker',
            //     'display_name' => 'Ops Checker',
            //     'code'         => 'OPS_CHECKER',
            //     'guard_name'   => 'web',
            //     'status'       => true,
            // ],

            // [
            //     'name'         => 'ops_manager',
            //     'display_name' => 'Ops Manager',
            //     'code'         => 'OPS_MANAGER',
            //     'guard_name'   => 'web',
            //     'status'       => true,
            // ],

            
            [
                'name'         => 'collection_manager',
                'display_name' => 'Collection Manager',
                'code'         => 'COLLECTION_MANAGER',
                'guard_name'   => 'web',
                'status'       => true,
            ],

            [
                'name'         => 'collection_executive',
                'display_name' => 'Collection Executive',
                'code'         => 'COLLECTION_EXECUTIVE',
                'guard_name'   => 'web',
                'status'       => true,
            ],

            [
                'name'         => 'partner_sub_user',
                'display_name' => 'Partner Sub User',
                'code'         => 'PARTNER_SUB_USER',
                'guard_name'   => 'web',
                'status'       => true,
            ],

             [
                'name'         => 'partner_administrative',
                'display_name' => 'Partner Administrative',
                'code'         => 'PARTNER_ADMINISTRATIVE',
                'guard_name'   => 'web',
                'status'       => true,
            ],

            [
                'name'         => 'partner_sales_person',
                'display_name' => 'Partner Sales Person',
                'code'         => 'PARTNER_SALES_PERSON',
                'guard_name'   => 'web',
                'status'       => true,
            ],
             [
                'name'         => 'partner_credit_person',
                'display_name' => 'Partner Credit Person',
                'code'         => 'PARTNER_CREDIT_PERSON',
                'guard_name'   => 'web',
                'status'       => true,
            ],

            [
               'name'         => 'partner_branch_manager',
               'display_name' => 'Partner Branch Manager',
               'code'         => 'PARTNER_BRANCH_MANAGER',
               'guard_name'   => 'web',
               'status'       => true,
           ],
           
            [
                'name'         => 'account',
                'display_name' => 'Account',
                'code'         => 'ACCOUNT',
                'guard_name'   => 'web',
                'status'       => true,
            ],

            
            [
                'name'         => 'viewer',
                'display_name' => 'Viewer',
                'code'         => 'VIEWER',
                'guard_name'   => 'web',
                'status'       => true,
            ],
              [
                'name'         => 'branch_manager',
                'display_name' => 'Branch Manager',
                'code'         => 'BRANCH_MANAGER',
                'guard_name'   => 'web',
                'status'       => true,
            ],

                        [
    'name'         => 'area_manager',
    'display_name' => 'Area Manager',
    'code'         => 'AREA_MANAGER',
    'guard_name'   => 'web',
    'status'       => true,
],
            [
                'name'         => 'branch_credit_checker',
                'display_name' => 'Branch Credit Checker',
                'code'         => 'BRANCH_CREDIT_CHECKER',
                'guard_name'   => 'web',
                'status'       => true,
            ],
            [
                'name'         => 'head_office_credit_checker',
                'display_name' => 'Head Office Credit Checker',
                'code'         => 'HEAD_OFFICE_CREDIT_CHECKER',
                'guard_name'   => 'web',
                'status'       => true,
            ],
            [
                 'name'         =>'head_office_credit_operations',
                'display_name' => 'Head Office Credit Operations',
                'code'         => 'HEAD_OFFICE_CREDIT_OPERATIONS',
                'guard_name'   => 'web',
                'status'       => true,
            ],
            [
                'name'         => 'sanction_authorized_person',
                'display_name' => 'Sanction Authorized Person',
                'code'         => 'SANCTION_AUTHORIZED_PERSON',
                'guard_name'   => 'web',
                'status'       => true,
            ],
            [
                'name'         => 'head_office_risk_control_unit',
                'display_name' => 'Head Office Risk Control Unit',
                'code'         => 'HEAD_OFFICE_RISK_CONTROL_UNIT',
                'guard_name'   => 'web',
                'status'       => true,
            ], 
        ];

        DB::transaction(function () use ($roles) {

            foreach ($roles as $role) {

                Role::updateOrCreate(

                    [
                        'code' => $role['code'],
                    ],

                    [
                        'name'         => $role['name'],
                        'display_name' => $role['display_name'],
                        'guard_name'   => $role['guard_name'],
                        'status'       => $role['status'],
                    ]
                );
            }
        });
    }
}