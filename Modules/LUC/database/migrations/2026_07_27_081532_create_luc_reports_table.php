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
        Schema::create('luc_reports', function (Blueprint $table) {
          $table->id();
            $table->uuid('uuid')->unique();

            $table->uuid('lead_uuid');
            $table->unsignedBigInteger('disbursement_id');

            // Historical snapshot used by the LUC report.
            $table->string('borrower_name');
            $table->string('loan_number')->nullable();
            $table->decimal('loan_amount', 15, 2);
            $table->date('disbursement_date');
            $table->date('luc_due_date');

            // fully_utilized | partly_utilized | mis_utilized
            $table->string('utilization_status', 30);
            $table->string('note_code', 80);
            $table->text('note_details')->nullable();
            $table->string('photo_path');
            $table->text('remarks')->nullable();

            // submitted | verified | rejected
            $table->string('workflow_status', 20)->default('submitted');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->string('verified_by_name')->nullable();
            $table->string('verified_by_role')->nullable();
            $table->date('date_of_luc');
            $table->timestamp('verified_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique('lead_uuid', 'luc_reports_lead_uuid_unique');
            $table->index(['utilization_status', 'workflow_status']);
            $table->index('luc_due_date');

            $table->foreign('lead_uuid')
                ->references('uuid')
                ->on('b_l_lead_creations')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreign('disbursement_id')
                ->references('id')
                ->on('b_l_disbursments')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreign('verified_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->foreign('created_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('luc_reports');
    }
};
