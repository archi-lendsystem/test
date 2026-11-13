<?php

namespace Modules\ProductScheme\Database\Seeders;

use Illuminate\Database\Seeder;

class ProductSchemeSeeder extends Seeder
{
    
    public function run(): void
    {
        if ($this->command) {
            $this->command->info('ProductSchemeSeeder skipped. Product schemes should be created from UI.');
        }
    }

}
