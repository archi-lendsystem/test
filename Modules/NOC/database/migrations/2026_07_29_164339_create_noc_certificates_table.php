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
        Schema::create('noc_certificates', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('repayment_account_id')
                ->constrained('repayment_accounts')
                ->cascadeOnDelete();

            $table->string('noc_number', 40)->unique();
            $table->date('issue_date');

            // NORMAL_CLOSURE, FORECLOSURE, SETTLEMENT.
            $table->string('closure_type', 20);

            $table->text('remarks')->nullable();
            $table->string('pdf_path')->nullable();

            $table->unsignedBigInteger('generated_by')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('noc_certificates');
    }
};
