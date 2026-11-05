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
        Schema::create('insurances', function (Blueprint $table) {
            $table->id();
                $table->uuid('lead_uuid');
            $table->foreign('lead_uuid')
                  ->references('uuid')
                  ->on('b_l_lead_creations')
                  ->onDelete('cascade');

            $table->decimal('insurance_percentage', 5, 2)->nullable(); 
            
            $table->decimal('insurance_amount', 15, 2)->nullable();
            
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('insurances');
    }
};
