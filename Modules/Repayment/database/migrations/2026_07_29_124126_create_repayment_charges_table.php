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
        Schema::create('repayment_charges', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('repayment_account_id')
                ->constrained('repayment_accounts')
                ->cascadeOnDelete();

            $table->foreignId('repayment_installment_id')
                ->nullable()
                ->constrained('repayment_installments')
                ->nullOnDelete();

            $table->foreignId('repayment_transaction_id')
                ->nullable()
                ->constrained('repayment_transactions')
                ->nullOnDelete();

            // BOUNCE or PENAL.
            $table->string('charge_type', 20);

            $table->date('charge_date');

            $table->decimal('base_amount', 18, 2);
            $table->decimal('gst_percentage', 5, 2);
            $table->decimal('gst_amount', 18, 2);
            $table->decimal('total_amount', 18, 2);

            $table->decimal('waived_amount', 18, 2)->default(0);
            $table->decimal('net_payable', 18, 2);

            // PENDING, WAIVED, PARTIALLY_WAIVED.
            $table->string('status', 20)->default('PENDING');

            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();

            $table->unsignedBigInteger('levied_by')->nullable();
            $table->unsignedBigInteger('waived_by')->nullable();
            $table->timestamp('waived_at')->nullable();
            $table->text('waiver_reason')->nullable();

            $table->timestamps();

            $table->index(['repayment_account_id', 'charge_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('repayment_charges');
    }
};
