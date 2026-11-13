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
        Schema::create('b_l_business_details', function (Blueprint $table) {
            $table->id();
             $table->uuid('lead_uuid');
            $table->foreign('lead_uuid')
                ->references('uuid')
                ->on('b_l_lead_creations')
                ->onDelete('cascade');

            $table->unsignedBigInteger('created_by')->nullable();

            $table->string('lead_id');

            // Correct Foreign Key
            $table->foreignId('applicant_id')
                ->constrained('b_l_applicants')
                ->cascadeOnDelete();

            $table->string('applicant_sno');
            $table->string('applicant_type');

            // Business Income Details
            $table->string('business_name')->nullable();
            $table->string('nature_of_business')->nullable();
            $table->date('incorporation_date')->nullable();
            $table->string('pan_number', 10)->nullable();
            $table->decimal('monthly_sales', 12, 2)->nullable();
            $table->text('office_address')->nullable();
            $table->string('pincode', 6)->nullable();
            $table->string('district')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();

            $table->enum('business_premise_ownership', [
                'Yes',
                'No',
                'Rented',
                'Leased',
            ])->nullable();

            $table->timestamps();

            // Performance Indexes
            $table->index(['lead_uuid', 'applicant_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('b_l_business_details');
    }
};
