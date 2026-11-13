<?php

namespace Modules\UserManagement\Database\Seeders;
use Illuminate\Database\Seeder;
use Modules\UserManagement\Database\Seeders\UserRoleSeeder;

class UserManagementDatabaseSeeder extends Seeder
{
   
    public function run(): void
    {
        

        $this->call([

           

            UserTypeSeeder::class,
            RoleSeeder::class,

          

            OrganizationSeeder::class,
            OrganizationBranchSeeder::class,

            

            UserSeeder::class,

           
            DepartmentSeeder::class,

            UserRoleSeeder::class,
        ]);
    }
}