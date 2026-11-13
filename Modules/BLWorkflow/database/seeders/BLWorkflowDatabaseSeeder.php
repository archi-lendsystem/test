<?php

namespace Modules\BLWorkflow\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\BLWorkflow\Database\Seeders\BLWorkflowStageSeeder;
use Modules\BLWorkflow\Database\Seeders\BLWorkflowStatusSeeder;
use Modules\BLWorkflow\Database\Seeders\BLWorkflowTransitionSeeder;
use Modules\BLWorkflow\Database\Seeders\BLStageRolePermissionSeeder;

class BLWorkflowDatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
         
        $this->call([
            BLWorkflowStageSeeder::class,
            BLWorkflowStatusSeeder::class,
            BLWorkflowTransitionSeeder::class,
            BLStageRolePermissionSeeder::class,
        ]);
    }
}
