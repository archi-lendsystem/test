<?php

namespace Modules\BLSanctionCondition\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\BLSanctionCondition\Database\Seeders\BLSanctionConditionMasterSeeder;

class BLSanctionConditionDatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    { 
         $this->call([
                 BLSanctionConditionMasterSeeder::class,

            ]);
    }
}
