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
        Schema::create('b_l_work_details', function (Blueprint $table) {
            $table->id();
            $table->uuid('lead_uuid');
            $table->foreign('lead_uuid')
                  ->references('uuid')
                  ->on('b_l_lead_creations')
                  ->onDelete('cascade');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('lead_id');
            $table->unsignedBigInteger('applicant_id');
            $table->string('applicant_sno');
            $table->string('applicant_type');
            $table->string('company_name')->nullable();
            $table->string('designation')->nullable();
            $table->string('salary_credit_method')->nullable();
            $table->decimal('monthly_salary', 10, 2)->nullable();
            $table->string('official_email')->nullable();
            $table->integer('total_work_company')->nullable();
            $table->enum('epf_deducted', ['Yes', 'No'])->nullable();
            $table->integer('working_months')->nullable();
            $table->index(['lead_uuid', 'applicant_id']);
            $table->foreign('applicant_id')
                  ->references('id')
                  ->on('b_l_applicants')
                  ->onDelete('cascade');
            $table->timestamps();
            
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('b_l_work_details');
    }
};
