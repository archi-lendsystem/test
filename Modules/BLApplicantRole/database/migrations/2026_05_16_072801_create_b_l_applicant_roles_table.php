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
        Schema::create('b_l_applicant_roles', function (Blueprint $table) {
            $table->id();
              $table->uuid('lead_uuid');
            $table->foreign('lead_uuid')->references('uuid')->on('b_l_lead_creations')->onDelete('cascade');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('lead_id');
            $table->integer('applicant_id');
            $table->string('applicant_sno')->nullable();
            $table->string('applicant_type')->nullable();
            $table->enum('type', ['Sole Proprietor', 'Partnership', 'Private Limited', 'Public Limited', 'Limited Liability Partnership', 'One Person Company']);
            $table->string('role')->nullable();
            $table->string('sno')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('b_l_applicant_roles');
    }
};
