<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foreclosures', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // A repayment account can be foreclosed only once.
            $table->foreignId('repayment_account_id')
                ->unique()
                ->constrained('repayment_accounts')
                ->restrictOnDelete();

            $table->foreignId('repayment_transaction_id')
                ->unique()
                ->constrained('repayment_transactions')
                ->restrictOnDelete();

            $table->foreignId('repayment_charge_id')
                ->nullable()
                ->unique()
                ->constrained('repayment_charges')
                ->nullOnDelete();

            // Amount entered/collected as full-and-final loan payoff.
            $table->decimal('foreclosure_amount', 18, 2);
            $table->date('foreclosure_date');

            // Charge snapshot; it must not change if charge configuration changes later.
            $table->decimal('foreclosure_charges', 18, 2)->default(0);
            $table->decimal('waived_charges', 18, 2)->default(0);
            $table->decimal('net_foreclosure_charges', 18, 2)->default(0);

            // Account balance snapshot taken immediately before closure.
            $table->decimal('principal_outstanding', 18, 2)->default(0);
            $table->decimal('interest_outstanding', 18, 2)->default(0);
            $table->decimal('total_outstanding', 18, 2)->default(0);

            $table->string('payment_mode', 30)->nullable();
            $table->string('transaction_reference', 150)->nullable();
            $table->text('remarks')->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedBigInteger('foreclosed_by')->nullable();
            $table->timestamps();

            $table->index(
                ['foreclosure_date', 'created_at'],
                'foreclosures_date_created_idx'
            );
            $table->index('foreclosed_by', 'foreclosures_actor_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foreclosures');
    }
};
