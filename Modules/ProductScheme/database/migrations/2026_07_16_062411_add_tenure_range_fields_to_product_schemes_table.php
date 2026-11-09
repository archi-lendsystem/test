<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('product_schemes', function (Blueprint $table) {

             $table->string('tenure_type', 20)->nullable();
                

            $table->unsignedInteger('minimum_tenure')
                ->nullable();

            $table->unsignedInteger('maximum_tenure')
                ->nullable();

            $table->index(
                ['tenure_type', 'tenure_unit', 'tenure_value'],
                'ps_fixed_tenure_idx'
            );

            $table->index(
                [
                    'tenure_type',
                    'tenure_unit',
                    'minimum_tenure',
                    'maximum_tenure',
                ],
                'ps_range_tenure_idx'
            );
            
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_schemes', function (Blueprint $table) {
            $table->dropIndex('ps_fixed_tenure_idx');
            $table->dropIndex('ps_range_tenure_idx');

            $table->dropColumn([
                'tenure_type',
                'minimum_tenure',
                'maximum_tenure',
            ]); 
        });
    }
};
