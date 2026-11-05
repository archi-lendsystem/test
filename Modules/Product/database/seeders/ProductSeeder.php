<?php

namespace Modules\Product\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Product\Models\Product;

class ProductSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $products = [

            [
                'name'   => 'Loan Against Property',
                'code'   => 'LAP',
                'status' => true,
            ],

            [
                'name'   => 'Business Loan',
                'code'   => 'BL',
                'status' => true,
            ],

            [
                'name'   => 'Personal Loan',
                'code'   => 'PL',
                'status' => true,
            ],

            [
                'name'   => 'Home Loan',
                'code'   => 'HL',
                'status' => true,
            ],
        ];

        foreach ($products as $product) {

            Product::updateOrCreate(

                [
                    'code' => $product['code'],
                ],

                [
                    'name'   => $product['name'],
                    'status' => $product['status'],
                ]
            );
        }

        if ($this->command) {
            $this->command->info('Products Seeded Successfully.');
        }
    }
}
