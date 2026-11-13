<?php

namespace Modules\UserManagement\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

use Modules\UserManagement\Models\OrganizationBranch;

class OrganizationBranchSeeder extends Seeder
{
    
    public function run(): void
    {
        DB::table('organization_branches')->insert([

          
            [
                'organization_id' => 1, 
                'user_type_id'    => 1,
                'parent_branch_id'=> null,

                'name'            => 'Delhi Head Office',
                'branch_code'     => 'DEL-HO',
                'type'            => 'HO',

                'address'         => 'Connaught Place, New Delhi',
                'pincode'         => '110001',
                'city'            => 'New Delhi',
                'state'           => 'Delhi',

                'is_active'       => true,

                'created_at'      => Carbon::now(),
                'updated_at'      => Carbon::now(),
            ],

            [
                'organization_id' => 1,
                'user_type_id'    => 1,
                'parent_branch_id'=> 1, 

                'name'            => 'Mumbai Branch',
                'branch_code'     => 'MUM-BR',
                'type'            => 'BRANCH',

                'address'         => 'Andheri East, Mumbai',
                'pincode'         => '400069',
                'city'            => 'Mumbai',
                'state'           => 'Maharashtra',

                'is_active'       => true,

                'created_at'      => Carbon::now(),
                'updated_at'      => Carbon::now(),
            ],

            [
                'organization_id' => 1,
                'user_type_id'    => 1,
                'parent_branch_id'=> 2,

                'name'            => 'Mumbai Sub Branch',
                'branch_code'     => 'MUM-SUB-01',
                'type'            => 'SUB_BRANCH',

                'address'         => 'Borivali West, Mumbai',
                'pincode'         => '400092',
                'city'            => 'Mumbai',
                'state'           => 'Maharashtra',

                'is_active'       => true,

                'created_at'      => Carbon::now(),
                'updated_at'      => Carbon::now(),
            ],
        ]);
    }
}