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
        Schema::create('b_l_reference_verifications', function (Blueprint $table) {
            $table->id();
             $table->uuid('lead_uuid');
            $table->foreign('lead_uuid')
                  ->references('uuid')
                  ->on('b_l_lead_creations')
                  ->onDelete('cascade');

            $table->foreignId('reference_id')
                  ->constrained('b_l_references')
                  ->cascadeOnDelete();

            $table->foreignId('reference_detail_id')
                  ->constrained('b_l_reference_details')
                  ->cascadeOnDelete();

            $table->string('status')->nullable();
            $table->text('remarks')->nullable();
            $table->String('Known_since')->nullable();

            $table->timestamps();
            $table->softDeletes(); 
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('b_l_reference_verifications');
    }
};
