<?php

namespace Modules\ProductScheme\Database\Seeders;

use Illuminate\Database\Seeder;

class ProductSchemeDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            ProductSchemeSeeder::class,
        ]);
    }
}
