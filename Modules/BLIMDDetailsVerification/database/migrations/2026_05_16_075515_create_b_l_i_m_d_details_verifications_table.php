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
        Schema::create('b_l_i_m_d_details_verifications', function (Blueprint $table) {
            $table->id();
            $table->uuid('lead_uuid');
            $table->foreign('lead_uuid')
                  ->references('uuid')
                  ->on('b_l_lead_creations')
                  ->onDelete('cascade');

            $table->string('lead_id');          
            $table->string('applicant_id');

            

            $table->enum('status', ['APPROVED', 'REJECTED', 'PENDING'])
                  ->default('PENDING');
            $table->text('remarks')->nullable();

            $table->boolean('consent_given')->default(false);
            $table->timestamp('consent_at')->nullable();

            $table->unsignedBigInteger('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('b_l_i_m_d_details_verifications');
    }
};
