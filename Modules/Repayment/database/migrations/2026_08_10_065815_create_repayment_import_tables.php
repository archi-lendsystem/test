<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * One record = one uploaded repayment collection Excel file.
         */
        Schema::create(
            'repayment_imports',
            function (Blueprint $table): void {
                $table->id();

                $table->uuid('uuid')->unique();

                $table->unsignedSmallInteger('template_version')
                    ->default(1);

                $table->string('original_filename', 255)
                    ->nullable();

                /*
                 * VALIDATING
                 * VALIDATED
                 * INVALID
                 * IMPORTING
                 * IMPORTED
                 * FAILED
                 */
                $table->string('status', 30)
                    ->default('VALIDATING');

                $table->unsignedInteger('total_rows')
                    ->default(0);

                $table->unsignedInteger('valid_rows')
                    ->default(0);

                $table->unsignedInteger('error_rows')
                    ->default(0);

                $table->decimal('total_amount', 18, 2)
                    ->default(0);

                $table->unsignedBigInteger('uploaded_by')
                    ->nullable();

                $table->timestamp('validated_at')
                    ->nullable();

                $table->timestamp('imported_at')
                    ->nullable();

                $table->json('metadata')
                    ->nullable();

                $table->timestamps();

                $table->index(
                    [
                        'status',
                        'created_at',
                    ],
                    'repay_import_status_created_idx'
                );

                $table->index(
                    'uploaded_by',
                    'repay_import_uploaded_by_idx'
                );
            }
        );

        /*
         * Each Excel collection row will be stored here
         * temporarily for validation/audit.
         *
         * This table is NOT the financial repayment ledger.
         */
        Schema::create(
            'repayment_import_rows',
            function (Blueprint $table): void {
                $table->id();

                /*
                 * Parent repayment Excel import.
                 */
                $table->unsignedBigInteger(
                    'repayment_import_id'
                );

                /*
                 * Actual Excel row number.
                 */
                $table->unsignedInteger(
                    'row_number'
                );

                /*
                 * Repayment account identity.
                 */
                $table->uuid(
                    'repayment_account_uuid'
                )->nullable();

                $table->string(
                    'loan_number',
                    50
                )->nullable();

                $table->uuid(
                    'lead_uuid'
                )->nullable();

                /*
                 * Amortization/schedule snapshot identity.
                 */
                $table->unsignedBigInteger(
                    'snapshot_amortization_id'
                )->nullable();

                $table->unsignedInteger(
                    'snapshot_schedule_version'
                )->nullable();

                /*
                 * Snapshot financial values.
                 */
                $table->decimal(
                    'snapshot_total_outstanding',
                    18,
                    2
                )->nullable();

                $table->decimal(
                    'snapshot_total_received',
                    18,
                    2
                )->nullable();

                $table->date(
                    'snapshot_next_due_date'
                )->nullable();

                /*
                 * Original/user-entered payment amount.
                 */
                $table->decimal(
                    'amount',
                    18,
                    2
                )->nullable();

                /*
                 * Actual amount collected.
                 */
                $table->decimal(
                    'amount_collected',
                    18,
                    2
                )->nullable();

                /*
                 * Actual collection date.
                 */
                $table->date(
                    'collection_date'
                )->nullable();

                /*
                 * CASH
                 * UPI
                 * NEFT
                 * RTGS
                 * IMPS
                 * NACH
                 * CHEQUE
                 * BANK_TRANSFER
                 * PAYMENT_GATEWAY
                 */
                $table->string(
                    'payment_mode',
                    30
                )->nullable();

                /*
                 * UTR specifically captured for collection.
                 */
                $table->string(
                    'utr_number',
                    150
                )->nullable();

                /*
                 * Existing generic transaction reference.
                 */
                $table->string(
                    'transaction_reference',
                    150
                )->nullable();

                /*
                 * Actual payment received date.
                 */
                $table->date(
                    'received_date'
                )->nullable();

                /*
                 * Accounting-effective date.
                 */
                $table->date(
                    'value_date'
                )->nullable();

                /*
                 * Penalty breakup.
                 */
                $table->decimal(
                    'penalty_base_amount',
                    18,
                    2
                )->default(0);

                $table->decimal(
                    'penalty_gst_amount',
                    18,
                    2
                )->default(0);

                $table->decimal(
                    'penalty_total_amount',
                    18,
                    2
                )->default(0);

                /*
                 * Bounce charges breakup.
                 */
                $table->decimal(
                    'bounce_base_amount',
                    18,
                    2
                )->default(0);

                $table->decimal(
                    'bounce_gst_amount',
                    18,
                    2
                )->default(0);

                $table->decimal(
                    'bounce_total_amount',
                    18,
                    2
                )->default(0);

                /*
                 * Amount finally allocated against EMI.
                 */
                $table->decimal(
                    'emi_allocation_amount',
                    18,
                    2
                )->default(0);

                /*
                 * User/operator who collected payment.
                 */
                $table->unsignedBigInteger(
                    'collected_by'
                )->nullable();

                /*
                 * Optional user remarks.
                 */
                $table->text(
                    'remarks'
                )->nullable();

                /*
                 * Original Excel row exactly as received.
                 */
                $table->json(
                    'raw_payload'
                )->nullable();

                /*
                 * VALID
                 * ERROR
                 */
                $table->string(
                    'validation_status',
                    20
                )->default('ERROR');

                /*
                 * Structured validation errors.
                 */
                $table->json(
                    'validation_errors'
                )->nullable();

                /*
                 * Actual repayment_transactions.id after import.
                 */
                $table->unsignedBigInteger(
                    'repayment_transaction_id'
                )->nullable();

                /*
                 * Individual row imported timestamp.
                 */
                $table->timestamp(
                    'imported_at'
                )->nullable();

                $table->timestamps();

                /*
                 * Same Excel row cannot exist twice in one import.
                 */
                $table->unique(
                    [
                        'repayment_import_id',
                        'row_number',
                    ],
                    'repay_import_row_unique'
                );

                /*
                 * VALID / ERROR row filtering.
                 */
                $table->index(
                    [
                        'repayment_import_id',
                        'validation_status',
                    ],
                    'repay_import_row_validation_idx'
                );

                /*
                 * Duplicate/reference checking.
                 */
                $table->index(
                    [
                        'repayment_account_uuid',
                        'transaction_reference',
                    ],
                    'repay_import_row_reference_idx'
                );

                /*
                 * Loan + collection date filtering.
                 */
                $table->index(
                    [
                        'loan_number',
                        'collection_date',
                    ],
                    'repay_import_row_loan_date_idx'
                );

                /*
                 * UTR lookup / duplicate checking.
                 */
                $table->index(
                    'utr_number',
                    'repay_import_row_utr_idx'
                );

                /*
                 * Parent import FK.
                 */
                $table->foreign(
                    'repayment_import_id',
                    'repay_import_row_import_fk'
                )
                    ->references('id')
                    ->on('repayment_imports')
                    ->cascadeOnDelete();
            }
        );
    }

    public function down(): void
    {
        /*
         * Child table first because of FK.
         */
        Schema::dropIfExists(
            'repayment_import_rows'
        );

        Schema::dropIfExists(
            'repayment_imports'
        );
    }
};