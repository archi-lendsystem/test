<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emi_statements', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->unsignedBigInteger('repayment_account_id');
            $table->foreign('repayment_account_id')->references('id')->on('repayment_accounts')->cascadeOnDelete();

            $table->string('statement_number')->unique();
            $table->date('generated_on');

            $table->unsignedInteger('total_installments')->default(0);
            $table->unsignedInteger('paid_installments')->default(0);
            $table->unsignedInteger('pending_installments')->default(0);
            $table->decimal('total_received_amount', 15, 2)->default(0);
            $table->decimal('total_pending_amount', 15, 2)->default(0);

            $table->string('pdf_path')->nullable();

            $table->unsignedBigInteger('generated_by')->nullable();
            $table->foreign('generated_by')->references('id')->on('users')->onDelete('set null');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emi_statements');
    }
};
