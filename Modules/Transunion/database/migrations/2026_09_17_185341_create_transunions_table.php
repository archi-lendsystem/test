<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transunions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            /* Lead mapping */
            $table->uuid('lead_uuid')->index();
            $table->string('lead_id')->nullable()->index();

            /* Applicant / Co-applicant / Guarantor mapping */
            $table->unsignedBigInteger('applicant_id')->index();
            $table->unsignedInteger('applicant_sno')->default(0);
            $table->string('applicant_type', 50)->index();

            /* User who triggered the bureau call */
            $table->unsignedBigInteger('requested_by')->nullable()->index();

            /* Snapshot of applicant details at the time of bureau call */
            $table->string('full_name')->nullable();
            $table->string('mobile_number', 15)->nullable();
            $table->string('pan_card_number', 10)->nullable();
            $table->date('date_of_birth')->nullable();

            /* Vendor identifiers */
            $table->string('reference_key')->nullable()->index();
            $table->string('request_id')->nullable()->index();
            $table->string('product_code')->nullable();
            $table->string('idempotency_key')->unique();

            /* Result */
            $table->string('status')->nullable()->index();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->integer('bureau_score')->nullable();
            $table->string('environment')->nullable();
            $table->boolean('billable')->default(false);
            $table->string('reconciliation_status')->nullable();

            /* Consent */
            $table->boolean('consent')->default(false);
            $table->timestamp('consented_at')->nullable();

            /* Audit request / response. PDF base64 is not stored here. */
            $table->longText('request_payload')->nullable();
            $table->longText('response_json')->nullable();

            /* PDF saved on configured local disk */
            $table->string('pdf_path')->nullable();
            $table->timestamp('pdf_generated_at')->nullable();

            $table->text('error_message')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('lead_uuid')
                ->references('uuid')
                ->on('b_l_lead_creations')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreign('applicant_id')
                ->references('id')
                ->on('b_l_applicants')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreign('requested_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->index(
                ['lead_uuid', 'applicant_id', 'created_at'],
                'transunion_lead_applicant_created_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transunions');
    }
};
