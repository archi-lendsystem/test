<?php

namespace Modules\BLDocumentCheckList\Database\Seeders;

use Illuminate\Database\Seeder;

class BLDocumentCheckListDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            BLDocumentCheckListSeeder::class,
        ]);
    }
}