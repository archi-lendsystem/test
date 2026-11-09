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
        Schema::create('b_l_scheme_selects', function (Blueprint $table) {
            $table->id();

            //blleadcreation link
             $table->uuid('lead_uuid');
            $table->foreign('lead_uuid')
                  ->references('uuid')
                  ->on('b_l_lead_creations')
                  ->onDelete('cascade');
           //productscheme link
           $table->uuid('product_scheme_uuid');

            $table->foreign('product_scheme_uuid')
                ->references('uuid')
                ->on('product_schemes')
                ->onDelete('cascade');
            
            $table->timestamps();
            //  $table->index('lead_uuid', 'bl_scheme_select_lead_uuid_idx');
            // $table->index('product_scheme_uuid', 'bl_scheme_select_scheme_uuid_idx');

               $table->unique(
                'lead_uuid',
                'bl_scheme_select_unique_lead_uuid'
            );

            $table->index(
                'product_scheme_uuid',
                'bl_scheme_select_scheme_uuid_idx'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('b_l_scheme_selects');
    }
};
