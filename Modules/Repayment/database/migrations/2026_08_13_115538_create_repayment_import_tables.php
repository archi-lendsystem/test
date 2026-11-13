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
        /*
         * One record = one uploaded repayment collection Excel file.
         *
         * Example:
         * August_Collections.xlsx
         */
        Schema::create(
            'repayment_imports',
            function (Blueprint $table): void {
                $table->id();

                /*
                 * Public/API identifier.
                 */
                $table->uuid('uuid')->unique();

                /*
                 * Excel template version.
                 *
                 * Future mein template structure change hone par
                 * old template safely identify/reject kar sakenge.
                 */
                $table->unsignedSmallInteger(
                    'template_version'
                )->default(1);

                /*
                 * Original uploaded Excel filename.
                 */
                $table->string(
                    'original_filename',
                    255
                )->nullable();

                /*
                 * Import lifecycle:
                 *
                 * VALIDATING
                 * VALIDATED
                 * INVALID
                 * IMPORTING
                 * IMPORTED
                 * FAILED
                 */
                $table->string(
                    'status',
                    30
                )->default('VALIDATING');

                /*
                 * Excel summary.
                 */
                $table->unsignedInteger(
                    'total_rows'
                )->default(0);

                $table->unsignedInteger(
                    'valid_rows'
                )->default(0);

                $table->unsignedInteger(
                    'error_rows'
                )->default(0);

                /*
                 * Total payment amount present in Excel.
                 *
                 * This is only audit/summary information.
                 * Actual financial posting existing repayment
                 * payment service se hogi.
                 */
                $table->decimal(
                    'total_amount',
                    18,
                    2
                )->default(0);

                /*
                 * Portal user/operator who uploaded the Excel.
                 */
                $table->unsignedBigInteger(
                    'uploaded_by'
                )->nullable();

                /*
                 * Validation successfully finish hone ka time.
                 */
                $table->timestamp(
                    'validated_at'
                )->nullable();

                /*
                 * Final payment import complete hone ka time.
                 */
                $table->timestamp(
                    'imported_at'
                )->nullable();

                /*
                 * Additional non-financial audit information.
                 *
                 * Example:
                 *
                 * template_generated_at
                 * source
                 * validation information
                 * import information
                 */
                $table->json(
                    'metadata'
                )->nullable();

                $table->timestamps();

                /*
                 * Import listing/status filtering.
                 */
                $table->index(
                    [
                        'status',
                        'created_at',
                    ],
                    'repay_import_status_created_idx'
                );

                /*
                 * User-specific import history.
                 */
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
         * IMPORTANT:
         *
         * This table is NOT the financial repayment ledger.
         *
         * Actual payment will be created later through the
         * existing repayment payment posting service.
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
                 *
                 * Example:
                 * Header = row 1
                 * First payment = row 2
                 */
                $table->unsignedInteger(
                    'row_number'
                );

                /*
                 * Repayment account identity.
                 *
                 * These values will come from our generated Excel
                 * and will be matched again with live DB values.
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
                * Amortization/schedule identity captured when
                * the Excel template was generated.
                *
                * Repayment installments are derived from amortization,
                * therefore final import must reject a stale template
                * if the repayment schedule has changed meanwhile.
                */
                $table->unsignedBigInteger(
                    'snapshot_amortization_id'
                )->nullable();

                $table->unsignedInteger(
                    'snapshot_schedule_version'
                )->nullable();

                /*
                 * Snapshot data captured when Excel was generated.
                 *
                 * These values are NEVER written back into
                 * repayment_accounts.
                 *
                 * Their purpose is only to detect whether:
                 *
                 * - Excel has been modified
                 * - account changed after Excel download
                 * - another payment was posted meanwhile
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
                 * User-entered collection amount.
                 */
                $table->decimal(
                    'amount',
                    18,
                    2
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
                 * UTR / UPI reference /
                 * cheque number / banking reference.
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
                 * Optional user remarks.
                 */
                $table->text(
                    'remarks'
                )->nullable();

                /*
                * Original ExcelJS row exactly as received.
                *
                * Invalid values may not fit typed staging columns.
                *
                * Example:
                * amount = "ABC"
                * received_date = "wrong-date"
                *
                * raw_payload preserves them for:
                * - user-friendly error preview
                * - downloadable error Excel
                * - audit/debugging
                *
                * This data is NEVER used directly for financial posting.
                */
                $table->json(
                    'raw_payload'
                )->nullable();

                /*
                 * Row validation result:
                 *
                 * VALID
                 * ERROR
                 *
                 * In V1:
                 *
                 * any financial mismatch = ERROR.
                 */
                $table->string(
                    'validation_status',
                    20
                )->default('ERROR');

                /*
                 * User-friendly structured validation errors.
                 *
                 * Example:
                 *
                 * [
                 *     {
                 *         "field": "transaction_reference",
                 *         "code": "REFERENCE_REQUIRED",
                 *         "message": "Transaction Reference is required for UPI."
                 *     }
                 * ]
                 */
                $table->json(
                    'validation_errors'
                )->nullable();

                /*
                 * After successful import this will contain the
                 * actual repayment_transactions.id.
                 *
                 * No DB foreign key intentionally for now because
                 * we do not want to alter/couple the existing
                 * production transaction structure.
                 */
                $table->unsignedBigInteger(
                    'repayment_transaction_id'
                )->nullable();

                /*
                 * Individual row successfully imported at.
                 */
                $table->timestamp(
                    'imported_at'
                )->nullable();

                $table->timestamps();

                /*
                 * Same Excel row cannot be staged twice
                 * inside the same import.
                 */
                $table->unique(
                    [
                        'repayment_import_id',
                        'row_number',
                    ],
                    'repay_import_row_unique'
                );

                /*
                 * Quickly get VALID / ERROR rows.
                 */
                $table->index(
                    [
                        'repayment_import_id',
                        'validation_status',
                    ],
                    'repay_import_row_validation_idx'
                );

                /*
                 * Duplicate/reference checking helper.
                 */
                $table->index(
                    [
                        'repayment_account_uuid',
                        'transaction_reference',
                    ],
                    'repay_import_row_reference_idx'
                );

                /*
                 * Both of these tables are new.
                 *
                 * Therefore this FK does NOT modify any old
                 * repayment production table.
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

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        /*
         * Child table first.
         */
        Schema::dropIfExists(
            'repayment_import_rows'
        );

        Schema::dropIfExists(
            'repayment_imports'
        );
    }
};