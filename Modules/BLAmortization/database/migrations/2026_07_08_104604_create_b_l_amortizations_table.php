<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('b_l_amortizations', function (Blueprint $table) {
            $table->id();

            $table->uuid('lead_uuid');

            $table->foreign('lead_uuid')
                ->references('uuid')
                ->on('b_l_lead_creations')
                ->onDelete('cascade');

            $table->decimal('loan_amount', 15, 2);

            $table->decimal('interest_rate', 6, 3);

            $table->decimal('tenure', 6, 2);

            $table->enum('tenure_unit', [
                'days',
                'weeks',
                'months',
                'years',
            ])->default('months');

            $table->enum('frequency', [
                'daily',
                'weekly',
                'bi_weekly',
                'monthly',
                'quarterly',
                'half_yearly',
                'yearly',
                'one_time',
                'bullet',
            ])->default('monthly');

            $table->date('start_date');

            $table->date('first_emi_date')
                ->nullable();

            $table->decimal('processing_fee', 15, 2)
                ->default(0);

            $table->enum('calculation_method', [
                'reducing_balance',
                'flat_rate',
                'daily_reducing',
            ])->default('reducing_balance');

            $table->decimal('installment_amount', 15, 2)
                ->nullable();

            $table->unsignedInteger('number_of_payments')
                ->nullable();

            $table->decimal('total_interest', 15, 2)
                ->nullable();

            $table->decimal('total_payable', 15, 2)
                ->nullable();

            $table->decimal('net_disbursed_amount', 15, 2)
                ->nullable();

            $table->json('schedule')
                ->nullable();

            $table->unsignedBigInteger('created_by')
                ->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('calculation_method');
            $table->index('start_date');
            $table->index('created_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('b_l_amortizations');
    }
};