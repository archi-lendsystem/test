<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'repayment_status_histories',
            function (Blueprint $table): void {
                $table->id();

                /*
                 * Public audit identifier.
                 */
                $table->uuid('uuid')->unique();

                /*
                 * Every history record belongs to an account.
                 */
                $table->unsignedBigInteger(
                    'repayment_account_id'
                );

                /*
                 * Account-level history mein null.
                 */
                $table->unsignedBigInteger(
                    'repayment_installment_id'
                )->nullable();

                /*
                 * Activation/daily refresh history mein null.
                 */
                $table->unsignedBigInteger(
                    'repayment_transaction_id'
                )->nullable();

                /*
                 * ACCOUNT_STATUS
                 * PAYMENT_STATUS
                 * DUE_STATUS
                 */
                $table->string('status_type', 30);

                /*
                 * Initial history mein old status null ho sakta hai.
                 */
                $table->string('old_status', 30)
                    ->nullable();

                $table->string('new_status', 30);

                /*
                 * ACTIVATION
                 * PAYMENT_POSTING
                 * PAYMENT_REVERSAL
                 * DAILY_STATUS_REFRESH
                 * ACCOUNT_CLOSURE
                 * SYSTEM
                 * MANUAL
                 */
                $table->string('source', 50);

                $table->text('reason')->nullable();

                $table->json('metadata')->nullable();

                /*
                 * Automated changes mein null.
                 */
                $table->unsignedBigInteger('changed_by')
                    ->nullable();

                /*
                 * Actual business-change timestamp.
                 */
                $table->timestamp('changed_at');

                $table->timestamps();

                /*
                 * Account history timeline.
                 */
                $table->index(
                    [
                        'repayment_account_id',
                        'changed_at',
                    ],
                    'repay_hist_account_changed_idx'
                );

                /*
                 * Installment history timeline.
                 */
                $table->index(
                    [
                        'repayment_installment_id',
                        'changed_at',
                    ],
                    'repay_hist_installment_changed_idx'
                );

                /*
                 * Status audit and reporting.
                 */
                $table->index(
                    [
                        'status_type',
                        'new_status',
                        'changed_at',
                    ],
                    'repay_hist_type_status_changed_idx'
                );

                $table->index(
                    'repayment_transaction_id',
                    'repay_hist_transaction_idx'
                );

                $table->index(
                    'changed_by',
                    'repay_hist_changed_by_idx'
                );

                $table->foreign(
                    'repayment_account_id',
                    'repay_hist_account_fk'
                )
                    ->references('id')
                    ->on('repayment_accounts')
                    ->restrictOnDelete();

                $table->foreign(
                    'repayment_installment_id',
                    'repay_hist_installment_fk'
                )
                    ->references('id')
                    ->on('repayment_installments')
                    ->restrictOnDelete();

                $table->foreign(
                    'repayment_transaction_id',
                    'repay_hist_transaction_fk'
                )
                    ->references('id')
                    ->on('repayment_transactions')
                    ->restrictOnDelete();
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'repayment_status_histories'
        );
    }
};