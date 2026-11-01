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
        Schema::create('b_l_disbursments', function (Blueprint $table) {
            $table->id();

            $table->uuid('lead_uuid');

            $table->foreign('lead_uuid')
                ->references('uuid')
                ->on('b_l_lead_creations')
                ->onDelete('cascade');

            $table->string('loan_number', 50)
                ->nullable()
                ->unique();

            $table->decimal('disbursement_amount', 15, 2)
                ->nullable();

            $table->date('disbursement_date')
                ->nullable();

            $table->date('first_emi_date')
                ->nullable();

            $table->string('bank_name')
                ->nullable();

            $table->string('utr_number')
                ->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('b_l_disbursments');
    }
};