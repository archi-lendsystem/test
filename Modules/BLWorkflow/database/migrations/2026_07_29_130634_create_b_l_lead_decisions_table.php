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
        Schema::create('b_l_lead_decisions', function (Blueprint $table) {
             $table->id();

            $table->uuid('lead_uuid');

            /*
             * Example:
             * CREDIT_VISIT_REPORT
             * TELE_VERIFICATION
             * SANCTION
             */
            $table->string('stage_code');

            /*
             * APPROVED, REJECTED, REOPENED
             */
            $table->string('decision');

            $table->text('remarks')->nullable();

            $table->unsignedBigInteger('decision_by');
            $table->timestamp('decision_at');

            /*
             * Current decision identify karne ke liye.
             * Old decisions history mein is_latest = false rahengi.
             */
            $table->boolean('is_latest')->default(true);

            /*
             * Reject ke baad reopen information.
             */
            $table->unsignedBigInteger('reopened_by')->nullable();
            $table->timestamp('reopened_at')->nullable();
            $table->text('reopen_remarks')->nullable();

            $table->timestamps();

            $table->foreign('lead_uuid')
                ->references('uuid')
                ->on('b_l_lead_creations')
                ->cascadeOnDelete();

            $table->index(['lead_uuid', 'stage_code']);
            $table->index(['lead_uuid', 'is_latest']);
            $table->index(['stage_code', 'decision']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('b_l_lead_decisions');
    }
};
