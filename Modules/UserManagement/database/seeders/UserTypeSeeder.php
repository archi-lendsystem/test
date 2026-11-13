<?php

namespace Modules\UserManagement\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class UserTypeSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('user_types')->insertOrIgnore([
            [
                'name' => 'employee',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'partner',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'vendor',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'technical_vendor',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'legal_vendor',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'pd_vendor',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'collection_vendor',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'lender',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}