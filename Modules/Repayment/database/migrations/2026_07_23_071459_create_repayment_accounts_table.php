<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'repayment_accounts',
            function (Blueprint $table): void {
                $table->id();

                /*
                 * Public API identifier.
                 */
                $table->uuid('uuid')->unique();

                /*
                 * Current implementation BL loan accounts ke liye hai.
                 */
                $table->string('loan_type', 20)
                    ->default('BL');

                $table->uuid('lead_uuid');

                $table->unsignedBigInteger('disbursement_id');

                $table->unsignedBigInteger('amortization_id');

                /*
                 * Example: BLN-20260722-000020
                 */
                $table->string('loan_number', 50);

                /*
                 * Initial amortization schedule version.
                 */
                $table->unsignedInteger('schedule_version')
                    ->default(1);

                /*
                 * Frozen loan terms captured during activation.
                 */
                $table->decimal('principal_amount', 18, 2);

                $table->decimal('net_disbursed_amount', 18, 2);

                $table->decimal('interest_rate', 10, 6)
                    ->default(0);

                $table->decimal('tenure', 10, 2);

                $table->string('tenure_unit', 20);

                $table->string('frequency', 30);

                $table->unsignedInteger('installment_count');

                $table->decimal('total_interest', 18, 2)
                    ->default(0);

                $table->decimal('total_payable', 18, 2);

                /*
                 * Live repayment balances.
                 */
                $table->decimal(
                    'principal_outstanding',
                    18,
                    2
                )->default(0);

                $table->decimal(
                    'interest_outstanding',
                    18,
                    2
                )->default(0);

                $table->decimal(
                    'total_outstanding',
                    18,
                    2
                )->default(0);

                /*
                 * Due date cross kar chuki unpaid monetary amount.
                 *
                 * Ye DPD calculation nahi hai.
                 */
                $table->decimal('overdue_amount', 18, 2)
                    ->default(0);

                /*
                 * Excess or currently unallocated customer payment.
                 */
                $table->decimal('advance_amount', 18, 2)
                    ->default(0);

                /*
                 * Successfully posted gross collections.
                 */
                $table->decimal('total_received', 18, 2)
                    ->default(0);

                /*
                 * Important repayment dates.
                 */
                $table->date('first_due_date')->nullable();

                $table->date('next_due_date')->nullable();

                $table->date('maturity_date')->nullable();

                $table->timestamp('last_payment_at')->nullable();

                /*
                 * Allowed values:
                 *
                 * ACTIVATION_PENDING
                 * ACTIVATION_FAILED
                 * ACTIVE
                 * SUSPENDED
                 * CLOSED
                 * CANCELLED
                 *
                 * OVERDUE account lifecycle status nahi hai.
                 * Installment overdue state installment table mein hogi.
                 */
                $table->string('status', 30)
                    ->default('ACTIVATION_PENDING');

                /*
                 * Frozen source values and activation diagnostics.
                 */
                $table->json('financial_snapshot')->nullable();

                $table->text('activation_error')->nullable();

                $table->timestamp('activated_at')->nullable();

                $table->timestamp('closed_at')->nullable();

                /*
                 * Automated operation mein user IDs null ho sakte hain.
                 */
                $table->unsignedBigInteger('created_by')
                    ->nullable();

                $table->unsignedBigInteger('updated_by')
                    ->nullable();

                $table->timestamps();

                /*
                 * One source record can activate only one account.
                 */
                $table->unique(
                    'disbursement_id',
                    'repay_acc_disbursement_unique'
                );

                $table->unique(
                    'amortization_id',
                    'repay_acc_amortization_unique'
                );

                $table->unique(
                    'loan_number',
                    'repay_acc_loan_number_unique'
                );

                /*
                 * Listing and search indexes.
                 */
                $table->index(
                    'lead_uuid',
                    'repay_acc_lead_idx'
                );

                $table->index(
                    ['loan_type', 'status'],
                    'repay_acc_type_status_idx'
                );

                $table->index(
                    ['status', 'next_due_date'],
                    'repay_acc_status_next_due_idx'
                );

                $table->index(
                    'maturity_date',
                    'repay_acc_maturity_idx'
                );

                /*
                 * Financial source records must not be hard-deleted
                 * after repayment activation.
                 */
                $table->foreign(
                    'lead_uuid',
                    'repay_acc_lead_fk'
                )
                    ->references('uuid')
                    ->on('b_l_lead_creations')
                    ->restrictOnDelete();

                $table->foreign(
                    'disbursement_id',
                    'repay_acc_disbursement_fk'
                )
                    ->references('id')
                    ->on('b_l_disbursments')
                    ->restrictOnDelete();

                $table->foreign(
                    'amortization_id',
                    'repay_acc_amortization_fk'
                )
                    ->references('id')
                    ->on('b_l_amortizations')
                    ->restrictOnDelete();
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('repayment_accounts');
    }
};