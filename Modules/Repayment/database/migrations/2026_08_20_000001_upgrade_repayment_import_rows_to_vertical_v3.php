<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repayment_import_rows', function (Blueprint $table): void {
            $table->decimal('amount_collected', 18, 2)->nullable()->after('amount');
            $table->date('collection_date')->nullable()->after('amount_collected');
            $table->string('utr_number', 150)->nullable()->after('payment_mode');
            $table->decimal('penalty_base_amount', 18, 2)->default(0);
            $table->decimal('penalty_gst_amount', 18, 2)->default(0);
            $table->decimal('penalty_total_amount', 18, 2)->default(0);
            $table->decimal('bounce_base_amount', 18, 2)->default(0);
            $table->decimal('bounce_gst_amount', 18, 2)->default(0);
            $table->decimal('bounce_total_amount', 18, 2)->default(0);
            $table->decimal('emi_allocation_amount', 18, 2)->default(0);
            $table->unsignedBigInteger('collected_by')->nullable();
            $table->index(['loan_number', 'collection_date'], 'repay_import_row_loan_date_idx');
            $table->index('utr_number', 'repay_import_row_utr_idx');
        });
    }

    public function down(): void
    {
        Schema::table('repayment_import_rows', function (Blueprint $table): void {
            $table->dropIndex('repay_import_row_loan_date_idx');
            $table->dropIndex('repay_import_row_utr_idx');
            $table->dropColumn([
                'amount_collected', 'collection_date', 'utr_number',
                'penalty_base_amount', 'penalty_gst_amount', 'penalty_total_amount',
                'bounce_base_amount', 'bounce_gst_amount', 'bounce_total_amount',
                'emi_allocation_amount', 'collected_by',
            ]);
        });
    }
};
