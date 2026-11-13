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
        Schema::create('b_l_insurances', function (Blueprint $table) {
               $table->id();

             $table->uuid('lead_uuid');
            $table->foreign('lead_uuid')
                  ->references('uuid')
                  ->on('b_l_lead_creations')
                  ->onDelete('cascade');

            $table->string('lead_id');  

            // Input fields
            $table->decimal('loan_amount', 15, 2);
            $table->integer('tenure');           // in months: 36,48,60,72,84,96,108,120
            $table->integer('age');              // borrower age: 18-65

            // Calculated fields (stored for record)
            $table->decimal('rate', 8, 4)->nullable();        // rate fetched from table
            $table->decimal('multiplier', 8, 4)->nullable();  // e.g. 7.17
            $table->decimal('premium_amount_before_gst', 15, 2)->nullable();
            $table->decimal('gst_amount', 15, 2)->nullable();
            $table->decimal('premium_amount_after_gst', 15, 2)->nullable();

            // Optional: store applicant/loan reference
            $table->string('applicant_name')->nullable();
            $table->string('loan_reference')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('b_l_insurances');
    }
};
