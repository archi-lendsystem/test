<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'repayment_transactions',
            function (Blueprint $table): void {
                $table->id();

                /*
                 * Public API identifier.
                 */
                $table->uuid('uuid')->unique();

                $table->unsignedBigInteger(
                    'repayment_account_id'
                );

                /*
                 * Backend-generated transaction number.
                 *
                 * Example: RPT-20260723-000001
                 */
                $table->string(
                    'transaction_number',
                    60
                )->unique();

                /*
                 * Duplicate request protection.
                 */
                $table->string(
                    'idempotency_key',
                    100
                )->unique();

                /*
                 * PAYMENT
                 * REVERSAL
                 * REFUND
                 * ADJUSTMENT
                 */
                $table->string('transaction_type', 30)
                    ->default('PAYMENT');

                /*
                 * Transaction amount is always stored as
                 * a positive monetary value.
                 *
                 * Direction transaction_type se determine hogi.
                 */
                $table->decimal('amount', 18, 2);

                $table->decimal(
                    'allocated_amount',
                    18,
                    2
                )->default(0);

                $table->decimal(
                    'unallocated_amount',
                    18,
                    2
                )->default(0);

                /*
                 * CASH
                 * CHEQUE
                 * UPI
                 * NEFT
                 * RTGS
                 * IMPS
                 * NACH
                 * BANK_TRANSFER
                 * PAYMENT_GATEWAY
                 */
                $table->string('payment_mode', 30)
                    ->nullable();

                /*
                 * UTR, UPI reference, cheque number,
                 * gateway transaction ID, etc.
                 *
                 * This field is intentionally not globally unique
                 * because reference semantics differ by payment mode.
                 */
                $table->string(
                    'transaction_reference',
                    150
                )->nullable();

                /*
                 * Actual customer payment date.
                 */
                $table->date('received_date');

                /*
                 * Accounting-effective date.
                 */
                $table->date('value_date');

                $table->timestamp('posted_at')
                    ->nullable();

                /*
                 * PENDING
                 * POSTED
                 * REVERSED
                 * FAILED
                 */
                $table->string('status', 20)
                    ->default('PENDING');

                /*
                 * REVERSAL transaction original transaction
                 * ko reference karegi.
                 */
                $table->unsignedBigInteger(
                    'reversal_of_transaction_id'
                )->nullable();

                $table->text('remarks')->nullable();

                /*
                 * Supplemental, non-financial context.
                 */
                $table->json('metadata')->nullable();

                $table->unsignedBigInteger('created_by')
                    ->nullable();

                $table->unsignedBigInteger('approved_by')
                    ->nullable();

                $table->timestamps();

                /*
                 * One original transaction can be reversed once.
                 *
                 * MariaDB multiple NULL values allow karta hai.
                 */
                $table->unique(
                    'reversal_of_transaction_id',
                    'repay_txn_reversal_of_unique'
                );

                /*
                 * Reference search/reconciliation index.
                 *
                 * Duplicate validation payment service mein
                 * payment mode and source policy ke basis par hogi.
                 */
                $table->index(
                    [
                        'payment_mode',
                        'transaction_reference',
                    ],
                    'repay_txn_mode_reference_idx'
                );

                /*
                 * Account transaction timeline.
                 */
                $table->index(
                    [
                        'repayment_account_id',
                        'received_date',
                    ],
                    'repay_txn_account_received_idx'
                );

                /*
                 * Pending/posted processing.
                 */
                $table->index(
                    [
                        'status',
                        'posted_at',
                    ],
                    'repay_txn_status_posted_idx'
                );

                /*
                 * Transaction reporting.
                 */
                $table->index(
                    [
                        'transaction_type',
                        'received_date',
                    ],
                    'repay_txn_type_received_idx'
                );

                $table->index(
                    'created_by',
                    'repay_txn_created_by_idx'
                );

                $table->foreign(
                    'repayment_account_id',
                    'repay_txn_account_fk'
                )
                    ->references('id')
                    ->on('repayment_accounts')
                    ->restrictOnDelete();

                $table->foreign(
                    'reversal_of_transaction_id',
                    'repay_txn_reversal_of_fk'
                )
                    ->references('id')
                    ->on('repayment_transactions')
                    ->restrictOnDelete();
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('repayment_transactions');
    }
};