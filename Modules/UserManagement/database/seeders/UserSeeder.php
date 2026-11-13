<?php

namespace Modules\UserManagement\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\UserManagement\Models\User;

class UserSeeder extends Seeder
{
   
    public function run(): void
    {
        $users = [

            [
                'user_code'          => 'ADMIN001',
                'user_name'          => 'Shubham Saini',
                'user_email'         => 'shubham@thelendsystem.com',
                'user_mobile_number' => '9999999999',
                'user_designation'   => 'Administrator',
            ],

            [
                'user_code'          => 'ADMIN002',
                'user_name'          => 'Sourav Manral',
                'user_email'         => 'sourav.manral@thelendsystem.com',
                'user_mobile_number' => '9876543210',
                'user_designation'   => 'Administrator',
            ],
              [
                'user_code'          => 'ADMIN004',
                'user_name'          => 'Pushpendra ahirwar',
                'user_email'         => 'pushpendra.ahirwar@thelendsystem.com',
                'user_mobile_number' => '7838208723',
                'user_designation'   => 'Administrator',
            ],

             [
                'user_code'          => 'ADMIN003',
                'user_name'          => 'Tarun Gupta',
                'user_email'         => 'tarun.gupta@thelendsystem.com',
                'user_mobile_number' => '9876543289',
                'user_designation'   => 'Administrator',
            ],
            
             [
                'user_code'          => 'ADMIN005',
                'user_name'          => 'roshani jindal',
                'user_email'         => 'roshni.jindal@thelendsystem.com',
                'user_mobile_number' => '9876543296',
                'user_designation'   => 'Administrator',
            ],

                [
                'user_code'          => 'ADMIN006',
                'user_name'          => 'juhi vyas',
                'user_email'         => 'juhi.vyas@thelendsystem.com',
                'user_mobile_number' => '9876543293',
                'user_designation'   => 'Administrator',
            ],

                [
                'user_code'          => 'ADMIN007',
                'user_name'          => 'abhishek bhardwaj',
                'user_email'         => 'abhishek.bhardwaj@thelendsystem.com',
                'user_mobile_number' => '9876543295',
                'user_designation'   => 'Administrator',
            ],
               [
                'user_code'          => 'ADMIN008',
                'user_name'          => 'archi rastogi',
                'user_email'         => 'archi.rastogi@thelendsystem.com',
                'user_mobile_number' => '9876543298',
                'user_designation'   => 'Administrator',
            ],


        ];

        foreach ($users as $user) {

            User::updateOrCreate(
                [
                    'user_email' => $user['user_email'],
                ],
                [

                

                    'uuid' => (string) Str::uuid(),

                

                    'created_by'           => null,
                    'parent_id'            => null,
                    'reporting_manager_id' => null,

                  

                    'user_type_id' => 1,


                    'organization_id' => null,
                    'branch_id'       => null,

                  

                    'user_code'          => $user['user_code'],
                    'user_name'          => $user['user_name'],
                    'user_email'         => $user['user_email'],
                    'user_mobile_number' => $user['user_mobile_number'],
                    'user_designation'   => $user['user_designation'],

                  

                    'address'  => null,
                    'pincode'  => null,
                    'district' => null,
                    'city'     => null,
                    'state'    => null,

                  

                    'is_active' => true,
                ]
            );
        }
    }
}