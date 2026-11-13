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
        Schema::create('payday_partner_cases', function (Blueprint $table) {
            $table->id();

            // ─── Case Identification ───────────────────────────────────────
            $table->string('case_number')->unique()->comment('Internal unique case reference');
            $table->string('partner_case_id')->nullable()->comment('Case ID returned by partner API');
            $table->unsignedBigInteger('partner_id')->comment('FK to partners table');
            $table->unsignedBigInteger('user_id')->nullable()->comment('FK to users/applicants table');

            // ─── Applicant / Loan Info ─────────────────────────────────────
            $table->string('applicant_name')->nullable();
            $table->string('applicant_mobile', 15)->nullable();
            $table->string('applicant_email')->nullable();
            $table->decimal('loan_amount', 12, 2)->nullable()->comment('Requested loan amount');
            $table->unsignedSmallInteger('loan_tenure_days')->nullable()->comment('Loan tenure in days');

            // ─── Local Disbursement Details ────────────────────────────────
            $table->decimal('disbursement_amount', 15, 2)->nullable()->comment('Final disbursement amount');
            $table->string('bank_name')->nullable()->comment('Bank name used for disbursement');
            $table->string('utr_number')->nullable()->comment('UTR / transaction reference number');
            $table->date('disbursement_date')->nullable()->comment('Actual disbursement date');
            $table->text('disbursement_remarks')->nullable()->comment('Approval, rejection, or disbursement remarks');

            // ─── API Request Details ───────────────────────────────────────
            $table->string('api_endpoint')->nullable()->comment('Partner API endpoint called');
            $table->string('api_method', 10)->default('POST')->comment('HTTP method: GET, POST, PUT etc.');
            $table->json('api_request_headers')->nullable()->comment('Sanitized request headers sent');
            $table->json('api_request_payload')->nullable()->comment('Request body/payload sent to partner');

            // ─── API Response Details ──────────────────────────────────────
            $table->unsignedSmallInteger('api_http_status_code')->nullable()->comment('HTTP status code e.g. 200, 201, 400, 500');
            $table->string('api_response_status')->nullable()->comment('Partner status string e.g. SUCCESS, FAILED, PENDING');
            $table->string('api_response_code')->nullable()->comment('Partner internal response/error code');
            $table->text('api_response_message')->nullable()->comment('Human-readable message from partner API');
            $table->json('api_response_body')->nullable()->comment('Full raw response body from partner');
            $table->decimal('api_response_time_ms', 10, 2)->nullable()->comment('API round-trip time in milliseconds');

            // ─── Case / Application Status ─────────────────────────────────
            $table->enum('case_status', [
                'initiated',
                'submitted',
                'under_review',
                'approved',
                'rejected',
                'disbursed',
                'cancelled',
                'error',
            ])->default('initiated')->comment('Current lifecycle status of the case');

            $table->enum('approval_status', [
                'pending',
                'approved',
                'rejected',
            ])->default('pending')->comment('Internal BL approval status');

            $table->enum('disbursement_status', [
                'pending',
                'approved',
                'rejected',
                'disbursed',
            ])->default('pending')->comment('Internal BL disbursement workflow status');

            $table->enum('api_call_status', [
                'pending',
                'success',
                'failed',
                'timeout',
                'retry',
            ])->default('pending')->comment('Status of the API call itself');

            // ─── Retry / Error Handling ────────────────────────────────────
            $table->unsignedTinyInteger('retry_count')->default(0)->comment('Number of API retry attempts');
            $table->timestamp('last_retry_at')->nullable()->comment('Timestamp of last retry attempt');
            $table->text('error_description')->nullable()->comment('Detailed error info if API call failed');

            // ─── Webhook / Callback ────────────────────────────────────────
            $table->json('webhook_payload')->nullable()->comment('Payload received from partner webhook/callback');
            $table->timestamp('webhook_received_at')->nullable()->comment('When the webhook was received');
            $table->enum('webhook_status', ['awaited', 'received', 'processed', 'failed'])
                ->default('awaited')
                ->comment('Lifecycle of webhook/callback from partner');

            // ─── Audit & Soft Delete ───────────────────────────────────────
            $table->string('created_by')->nullable()->comment('User or system that created this record');
            $table->string('updated_by')->nullable()->comment('User or system that last updated this record');

            $table->unsignedBigInteger('approved_by')->nullable()->comment('User ID who approved the case');
            $table->unsignedBigInteger('rejected_by')->nullable()->comment('User ID who rejected the case');
            $table->unsignedBigInteger('disbursed_by')->nullable()->comment('User ID who marked the case as disbursed');

            $table->timestamp('approved_at')->nullable()->comment('Approval timestamp');
            $table->timestamp('rejected_at')->nullable()->comment('Rejection timestamp');
            $table->timestamp('disbursed_at')->nullable()->comment('Disbursement timestamp');

            $table->timestamps();
            $table->softDeletes();

            // ─── Indexes ───────────────────────────────────────────────────
            $table->index('partner_id');
            $table->index('user_id');
            $table->index('case_status');
            $table->index('approval_status');
            $table->index('disbursement_status');
            $table->index('api_call_status');
            $table->index('api_http_status_code');
            $table->index('partner_case_id');
            $table->index('disbursement_date');
            $table->index('utr_number');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payday_partner_cases');
    }
};