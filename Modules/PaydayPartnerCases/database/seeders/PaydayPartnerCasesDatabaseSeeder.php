<?php

namespace Modules\PaydayPartnerCases\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\PaydayPartnerCases\Database\Seeders\PaydayPartnerCaseSeeder;

class PaydayPartnerCasesDatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
         $this->call([
            PaydayPartnerCaseSeeder::class,
        ]);
    }
}
