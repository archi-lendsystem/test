<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One record in this table represents one EMI/installment
     * selected for collection inside one repayment import row.
     *
     * Structure:
     *
     * repayment_imports
     *      ↓
     * repayment_import_rows
     *      ↓
     * repayment_import_installments
     *
     * IMPORTANT:
     *
     * This table is only for bulk Excel staging, validation
     * and audit.
     *
     * It is NOT the live repayment installment table and
     * it is NOT part of the financial ledger.
     *
     * Actual EMI state remains in:
     * repayment_installments
     *
     * Actual payment posting remains in:
     * repayment_transactions
     * repayment_allocations
     */
    public function up(): void
    {
        Schema::create(
            'repayment_import_installments',
            function (Blueprint $table): void {
                $table->id();

                /*
                 * =====================================================
                 * PARENT IMPORT ROW
                 * =====================================================
                 *
                 * One repayment_import_row represents one loan/account
                 * row from the horizontal Excel template.
                 *
                 * Example:
                 *
                 * Loan A | EMI 1 | EMI 2 | EMI 3
                 *
                 * Loan A itself is stored in repayment_import_rows.
                 * The filled EMI cells are stored in this table.
                 */
                $table->unsignedBigInteger(
                    'repayment_import_row_id'
                );

                /*
                 * =====================================================
                 * EXCEL LOCATION
                 * =====================================================
                 *
                 * Optional Excel column identifier used for precise
                 * preview/error reporting.
                 *
                 * Examples:
                 *
                 * H
                 * I
                 * J
                 *
                 * or frontend-generated identifiers such as:
                 *
                 * EMI_001
                 * EMI_002
                 *
                 * Backend financial posting must never depend on this
                 * value. It is only display/audit information.
                 */
                $table->string(
                    'excel_column',
                    30
                )->nullable();

                /*
                 * =====================================================
                 * LIVE INSTALLMENT IDENTITY SNAPSHOT
                 * =====================================================
                 *
                 * UUID of the repayment_installments record that this
                 * Excel EMI column represented when the template was
                 * generated.
                 *
                 * Intentionally nullable because malformed/tampered
                 * Excel input should still be stageable as an ERROR
                 * for user-friendly preview.
                 *
                 * IMPORTANT:
                 *
                 * We intentionally DO NOT create a foreign key from
                 * this UUID to repayment_installments.uuid.
                 *
                 * Reasons:
                 *
                 * 1. Invalid/tampered UUID values must be preservable.
                 * 2. Old/stale template data must remain auditable.
                 * 3. Live installment identity will always be checked
                 *    again in application validation.
                 * 4. Final confirmation performs fresh DB validation.
                 */
                $table->uuid(
                    'repayment_installment_uuid'
                )->nullable();

                /*
                 * EMI number shown in the repayment schedule.
                 *
                 * Examples:
                 *
                 * 1
                 * 2
                 * 3
                 * ...
                 */
                $table->unsignedInteger(
                    'installment_number'
                )->nullable();

                /*
                 * Schedule version captured when template data was
                 * generated.
                 *
                 * Parent repayment_import_rows also contains account
                 * schedule-version snapshot.
                 *
                 * Keeping the EMI-level value gives us an explicit
                 * audit trail for each targeted installment.
                 */
                $table->unsignedInteger(
                    'snapshot_schedule_version'
                )->nullable();

                /*
                 * =====================================================
                 * INSTALLMENT SNAPSHOT
                 * =====================================================
                 *
                 * These are frozen values copied from the live
                 * repayment_installments record when the Excel template
                 * was generated.
                 *
                 * They must NEVER be written back into
                 * repayment_installments.
                 *
                 * Their purpose is:
                 *
                 * - Excel integrity validation
                 * - stale-template detection
                 * - preview
                 * - audit
                 * - troubleshooting
                 */

                /*
                 * EMI due date shown to the operator.
                 */
                $table->date(
                    'snapshot_due_date'
                )->nullable();

                /*
                 * Original scheduled EMI amount.
                 *
                 * Normally:
                 *
                 * principal_due + interest_due
                 */
                $table->decimal(
                    'snapshot_installment_amount',
                    18,
                    2
                )->nullable();

                /*
                 * Scheduled principal component.
                 */
                $table->decimal(
                    'snapshot_principal_due',
                    18,
                    2
                )->nullable();

                /*
                 * Scheduled interest component.
                 */
                $table->decimal(
                    'snapshot_interest_due',
                    18,
                    2
                )->nullable();

                /*
                 * Principal already paid against this EMI when
                 * template data was generated.
                 */
                $table->decimal(
                    'snapshot_principal_paid',
                    18,
                    2
                )->nullable();

                /*
                 * Interest already paid against this EMI when
                 * template data was generated.
                 */
                $table->decimal(
                    'snapshot_interest_paid',
                    18,
                    2
                )->nullable();

                /*
                 * Remaining amount of this EMI at template-generation
                 * time.
                 *
                 * This is an important stale-payment check.
                 *
                 * Example:
                 *
                 * Excel snapshot = 5,000
                 * Live confirm   = 3,000
                 *
                 * Means repayment state changed after template
                 * generation/validation.
                 */
                $table->decimal(
                    'snapshot_outstanding_amount',
                    18,
                    2
                )->nullable();

                /*
                 * Expected values:
                 *
                 * UNPAID
                 * PARTIAL
                 * PAID
                 */
                $table->string(
                    'snapshot_payment_status',
                    20
                )->nullable();

                /*
                 * Expected values:
                 *
                 * UPCOMING
                 * DUE
                 * OVERDUE
                 *
                 * NOTE:
                 *
                 * due_status naturally changes with business date.
                 * Therefore it is useful for template/audit display,
                 * but a date rollover alone should not automatically
                 * be treated as a financial stale-data conflict.
                 */
                $table->string(
                    'snapshot_due_status',
                    20
                )->nullable();

                /*
                 * =====================================================
                 * USER-ENTERED COLLECTION
                 * =====================================================
                 *
                 * Amount entered by operator for this specific EMI.
                 *
                 * Example:
                 *
                 * Scheduled outstanding = 5,000
                 * Operator collection    = 2,000
                 *
                 * Partial collection is allowed if business
                 * validation permits it.
                 *
                 * Blank Excel EMI cells should normally NOT create
                 * records in this table.
                 *
                 * Nullable is still required so malformed values can
                 * be represented using raw_payload + validation error.
                 */
                $table->decimal(
                    'collection_amount',
                    18,
                    2
                )->nullable();

                /*
                 * =====================================================
                 * VALIDATION RESULT
                 * =====================================================
                 *
                 * Allowed application values:
                 *
                 * VALID
                 * ERROR
                 */
                $table->string(
                    'validation_status',
                    20
                )->default('ERROR');

                /*
                 * Structured EMI-level validation errors.
                 *
                 * Example:
                 *
                 * [
                 *     {
                 *         "field": "collection_amount",
                 *         "code": "AMOUNT_EXCEEDS_INSTALLMENT_OUTSTANDING",
                 *         "message": "Collection amount cannot exceed the current EMI outstanding amount."
                 *     }
                 * ]
                 */
                $table->json(
                    'validation_errors'
                )->nullable();

                /*
                 * =====================================================
                 * RAW INPUT
                 * =====================================================
                 *
                 * Original installment object received from frontend.
                 *
                 * Example:
                 *
                 * {
                 *     "installment_uuid": "...",
                 *     "installment_number": 2,
                 *     "due_date": "2026-10-16",
                 *     "collection_amount": "500.00"
                 * }
                 *
                 * This is important because invalid values may not fit
                 * typed database columns.
                 *
                 * Example:
                 *
                 * collection_amount = "ABC"
                 *
                 * Typed collection_amount remains null while the exact
                 * original value remains preserved here.
                 *
                 * raw_payload must NEVER be used directly for
                 * financial posting.
                 */
                $table->json(
                    'raw_payload'
                )->nullable();

                /*
                 * Additional non-financial validation/posting audit
                 * information.
                 *
                 * Examples:
                 *
                 * validation comparison
                 * stale-check diagnostics
                 * import/posting notes
                 *
                 * Financial truth must still come from the repayment
                 * ledger tables.
                 */
                $table->json(
                    'metadata'
                )->nullable();

                /*
                 * Set only after this EMI collection was successfully
                 * included in the final confirmed import.
                 *
                 * Parent repayment_import_rows stores the actual
                 * repayment_transaction_id.
                 *
                 * We do not duplicate transaction ID here because one
                 * parent transaction can contain multiple targeted EMI
                 * allocations.
                 */
                $table->timestamp(
                    'imported_at'
                )->nullable();

                $table->timestamps();

                /*
                 * =====================================================
                 * UNIQUENESS
                 * =====================================================
                 */

                /*
                 * Same EMI number cannot be submitted twice inside
                 * one account/import row.
                 *
                 * Nullable installment_number still allows malformed
                 * records to be staged for preview.
                 */
                $table->unique(
                    [
                        'repayment_import_row_id',
                        'installment_number',
                    ],
                    'repay_imp_inst_row_number_unique'
                );

                /*
                 * Same live installment UUID cannot be submitted twice
                 * inside one account/import row.
                 *
                 * This provides another DB-level protection against
                 * duplicate targeted EMI entries.
                 *
                 * Multiple NULL values remain possible for malformed
                 * rows that need validation preview.
                 */
                $table->unique(
                    [
                        'repayment_import_row_id',
                        'repayment_installment_uuid',
                    ],
                    'repay_imp_inst_row_uuid_unique'
                );

                /*
                 * =====================================================
                 * INDEXES
                 * =====================================================
                 */

                /*
                 * Preview:
                 *
                 * Fetch all VALID / ERROR EMI entries belonging to one
                 * Excel account row.
                 */
                $table->index(
                    [
                        'repayment_import_row_id',
                        'validation_status',
                    ],
                    'repay_imp_inst_row_validation_idx'
                );

                /*
                 * Useful during live installment lookup, reconciliation
                 * and audit investigation.
                 */
                $table->index(
                    'repayment_installment_uuid',
                    'repay_imp_inst_uuid_idx'
                );

                /*
                 * =====================================================
                 * FOREIGN KEY
                 * =====================================================
                 *
                 * Only the staging parent relationship gets a physical
                 * FK.
                 *
                 * Deleting an import row should also delete its child
                 * EMI staging records.
                 *
                 * This does NOT cascade into:
                 *
                 * repayment_accounts
                 * repayment_installments
                 * repayment_transactions
                 * repayment_allocations
                 *
                 * therefore financial records are unaffected.
                 */
                $table->foreign(
                    'repayment_import_row_id',
                    'repay_imp_inst_row_fk'
                )
                    ->references('id')
                    ->on('repayment_import_rows')
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
         * Only the new bulk-import EMI staging table is removed.
         *
         * Existing repayment financial tables and existing import
         * parent tables are untouched.
         */
        Schema::dropIfExists(
            'repayment_import_installments'
        );
    }
};