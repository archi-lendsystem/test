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
        Schema::create('follow_ups', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('repayment_account_id')
                ->constrained('repayment_accounts')
                ->cascadeOnDelete();

            // CALL, VISIT, SMS, EMAIL, WHATSAPP, LETTER, OTHER.
            $table->string('follow_up_type', 20);

            $table->dateTime('follow_up_date');
            $table->text('remarks')->nullable();

            // PTP_TAKEN, NO_RESPONSE, REFUSED, DISPUTED, ALREADY_PAID, OTHER.
            $table->string('outcome', 20);

            $table->date('next_follow_up_date')->nullable();

            // Populated only when outcome = PTP_TAKEN.
            $table->decimal('ptp_amount', 18, 2)->nullable();
            $table->date('ptp_date')->nullable();
            $table->string('ptp_status', 20)->nullable();
            $table->unsignedBigInteger('ptp_resolved_by')->nullable();
            $table->timestamp('ptp_resolved_at')->nullable();
            $table->text('ptp_resolution_remarks')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();

            $table->timestamps();

            $table->index(['repayment_account_id', 'follow_up_date']);
            $table->index('ptp_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('follow_ups');
    }
};
