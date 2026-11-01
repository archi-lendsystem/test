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
        Schema::create('b_l_lead_creations', function (Blueprint $table) {
            $table->id();

            $table->uuid('uuid')->unique();
            $table->string('lead_id')->unique();

            $table->unsignedBigInteger('created_by')->nullable();

            $table->foreignId('branch_id')
                ->nullable()
                ->constrained('organization_branches')
                ->nullOnDelete();

                    $table->foreignId('product_id')
                ->nullable()
                ->constrained('products')
                ->nullOnDelete();

            $table->string('loan_type')->nullable();
            $table->string('customer_type')->nullable();
            $table->string('sub_loan_type')->nullable();
            $table->string('source_by')->nullable();
            $table->string('email')->nullable();
            $table->string('mobile_number')->nullable();
            $table->string('loan_amount')->nullable();
            $table->string('loan_tenur')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('b_l_lead_creations');
    }
};