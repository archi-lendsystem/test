<?php

namespace Modules\UserManagement\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Modules\UserManagement\Models\Organization;

class OrganizationSeeder extends Seeder
{
    
    public function run(): void
    {
        DB::table('organizations')->insert([
            [
                'uuid'         => Str::uuid(),
                'user_type_id' => 1, 
                'name'         => 'Sabrimala',
                'code'         => 'CAPSAFE',
                'gstin'        => '07ABCDE1234F1Z5',
                'pan'          => 'ABCDE1234F',
                'email'        => 'info@capsafe.com',
                'phone'        => '9876543210',
                'is_active'    => true,
                'created_at'   => Carbon::now(),
                'updated_at'   => Carbon::now(),
            ],

          
        ]);
    }
}