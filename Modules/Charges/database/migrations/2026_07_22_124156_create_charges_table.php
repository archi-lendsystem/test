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
        Schema::create('charges', function (Blueprint $table) {
            $table->id();

               $table->uuid('lead_uuid');
            $table->foreign('lead_uuid')
                  ->references('uuid')
                  ->on('b_l_lead_creations')
                  ->onDelete('cascade');
            
            $table->decimal('platform_charges', 15, 2)->nullable();
            $table->decimal('sms_changes', 15, 2)->nullable();
            $table->decimal('application_charges', 15, 2)->nullable();
            $table->decimal('other_charges', 15, 2)->nullable();
$table->decimal('total_charges', 15, 2)->nullable();


            $table->timestamps();
            $table->softDeletes();
            
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('charges');
    }
};
