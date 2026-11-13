<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('b_l_otp_verifications', function (Blueprint $table) {
                $table->id();
              $table->uuid('uuid')->default(DB::raw('(UUID())'));
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('lead_id');
            $table->string('otp');
              $table->boolean('used')->default(false);
            $table->boolean('is_verified')->default(false);
              $table->boolean('credit_bureau_consent')->default(false);
    $table->boolean('data_processing_consent')->default(false);
    $table->boolean('loan_agreement_consent')->default(false);
    $table->boolean('sms_email_consent')->default(false);
    $table->boolean('verified')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('b_l_otp_verifications');
    }
};
