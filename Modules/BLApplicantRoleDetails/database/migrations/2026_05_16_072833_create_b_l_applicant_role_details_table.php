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
        Schema::create('b_l_applicant_role_details', function (Blueprint $table) {
            $table->id();
             $table->uuid('lead_uuid');
            $table->foreign('lead_uuid')->references('uuid')->on('b_l_lead_creations')->onDelete('cascade');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('lead_id');
            $table->integer('applicant_role_id');
            $table->integer('applicant_id');
            $table->string('applicant_sno')->nullable();
            $table->string('applicant_type')->nullable();
            $table->enum('type', ['Sole Proprietor', 'Partnership', 'Private Limited', 'Public Limited', 'Limited Liability Partnership', 'One Person Company']);
            $table->string('role')->nullable();
            $table->string('sno')->nullable();
            $table->string('full_name')->nullable();
            $table->string('mother_name')->nullable();
            $table->string('date_of_birth')->nullable();
            $table->string('mobile_number')->nullable();
            $table->string('gender')->nullable();
            $table->string('pan_number')->nullable();
            $table->string('residence_address')->nullable();
            $table->string('pincode')->nullable();
            $table->string('district')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('ownership_status')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('b_l_applicant_role_details');
    }
};
