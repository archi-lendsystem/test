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
        Schema::create('repayment_settlements', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('repayment_account_id')
                ->constrained('repayment_accounts')
                ->cascadeOnDelete();

            $table->decimal('total_outstanding_at_proposal', 18, 2);
            $table->decimal('settlement_amount', 18, 2);
            $table->decimal('waived_amount', 18, 2);

            // PROPOSED, APPROVED, REJECTED, PAID.
            $table->string('status', 20)->default('PROPOSED');

            $table->text('reason')->nullable();

            $table->unsignedBigInteger('proposed_by')->nullable();

            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('decision_remarks')->nullable();

            $table->timestamp('paid_at')->nullable();
            $table->string('paid_reference')->nullable();

            $table->timestamps();

            $table->index(['repayment_account_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('repayment_settlements');
    }
};
