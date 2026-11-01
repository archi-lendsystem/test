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
        Schema::create('product_schemes', function (Blueprint $table) {
            $table->id();

            $table->uuid('uuid')->unique();

            $table->foreignId('product_id')
                ->nullable()
                ->constrained('products')
                ->nullOnDelete();

            $table->string('product_name', 255);

            $table->string('scheme_name', 255)->nullable();

            $table->string('sub_loan_type', 50)->nullable();

            $table->decimal('interest_rate', 8, 2)->default(0);

            $table->string('repayment_frequency', 50)->nullable();

            $table->decimal('lpf_rate', 8, 2)->default(0);

            $table->float('platform_charges')->default(0);

            $table->float('sms_charges')->default(0);

            $table->unsignedInteger('minimum_loan_amount')->default(0);

            $table->unsignedInteger('maximum_loan_amount')->default(0);

            $table->decimal('minimum_pf', 8, 2)->default(0);

            $table->decimal('maximum_pf', 8, 2)->default(0);

            $table->unsignedInteger('moratorium_days')->default(0);

            $table->string('calculation_method', 100)->nullable();

            $table->string('overdue_interest_on', 100)->nullable();

            $table->string('emi_round_off_type', 50)->default('nearest');

            $table->decimal('emi_round_up_value', 8, 2)->default(0);

            $table->string('tenure_type', 20)->nullable();

            $table->unsignedInteger('tenure_value')->nullable();

            $table->unsignedInteger('minimum_tenure')->nullable();

            $table->unsignedInteger('maximum_tenure')->nullable();

            $table->string('tenure_unit', 30)->default('months');

            $table->date('valid_from')->nullable();

            $table->date('valid_till')->nullable();

            $table->text('remarks')->nullable();

            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(
                ['product_id', 'is_active'],
                'ps_product_active_idx'
            );

            $table->index(
                ['sub_loan_type', 'repayment_frequency'],
                'ps_subloan_repayment_idx'
            );

            $table->index(
                ['valid_from', 'valid_till'],
                'ps_validity_idx'
            );

            $table->index(
                ['tenure_type', 'tenure_unit', 'tenure_value'],
                'ps_fixed_tenure_idx'
            );

            $table->index(
                [
                    'tenure_type',
                    'tenure_unit',
                    'minimum_tenure',
                    'maximum_tenure',
                ],
                'ps_range_tenure_idx'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_schemes');
    }
};