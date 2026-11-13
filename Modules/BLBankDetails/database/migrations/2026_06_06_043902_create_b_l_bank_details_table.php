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
        Schema::create('b_l_bank_details', function (Blueprint $table) {
            $table->id();
             $table->uuid('lead_uuid');
            $table->foreign('lead_uuid')
                ->references('uuid')
                ->on('b_l_lead_creations')
                ->onDelete('cascade');

            $table->string('lead_id');

            // ── Applicant Reference ───────────────────────────────────
            $table->foreignId('applicant_id')
                ->constrained('b_l_applicants')
                ->cascadeOnDelete();

            $table->string('applicant_sno')->nullable();
            $table->string('applicant_type')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();

            // ── Bank Details ──────────────────────────────────────────
           $table->string('account_holder_name');
            $table->string('bank_name');
             $table->string('account_number');          
            $table->string('ifsc_code', 11); 
            $table->enum('account_type', [
                'Savings',
                'Current',
                
            ]);

            // ── Statement Details (New) ───────────────────────────────
            $table->string('statement_file')->nullable();
            $table->string('statement_password')->nullable(); 


            $table->timestamps();
            $table->softDeletes();

            // ── Indexes ───────────────────────────────────────────────
            $table->index(['lead_uuid', 'applicant_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('b_l_bank_details');
    }
};
